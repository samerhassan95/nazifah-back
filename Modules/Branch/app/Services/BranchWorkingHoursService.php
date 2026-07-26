<?php

namespace Modules\Branch\Services;

use Carbon\Carbon;
use Illuminate\Support\Str;
use Modules\Branch\Models\Branch;
use Modules\Branch\Models\BranchWorkingHourShift;

class BranchWorkingHoursService
{
    public const DAYS = [
        'saturday',
        'sunday',
        'monday',
        'tuesday',
        'wednesday',
        'thursday',
        'friday',
    ];

    public function isLegacyFormat(?array $input): bool
    {
        if (! $input || $input === []) {
            return false;
        }

        return array_is_list($input);
    }

    public function emptySchedule(): array
    {
        return array_fill_keys(self::DAYS, []);
    }

    public function normalizeInput(?array $input): array
    {
        $normalized = $this->emptySchedule();

        if (! $input) {
            return $normalized;
        }

        if ($this->isLegacyFormat($input)) {
            $shifts = $this->normalizeShiftsList($input);

            foreach (self::DAYS as $day) {
                $normalized[$day] = $shifts;
            }

            return $normalized;
        }

        foreach ($input as $dayKey => $shifts) {
            $day = $this->normalizeDayKey($dayKey);
            if ($day === null) {
                continue;
            }

            $normalized[$day] = $this->normalizeShiftsList(is_array($shifts) ? $shifts : []);
        }

        return $normalized;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function validate(?array $input): array
    {
        $errors = [];

        if ($input === null) {
            return $errors;
        }

        if (! is_array($input)) {
            $errors['working_hours'] = [__('branch.working_hours_invalid')];

            return $errors;
        }

        if ($this->isLegacyFormat($input)) {
            return $this->validateShiftsList($input, 'working_hours');
        }

        foreach ($input as $dayKey => $shifts) {
            $day = $this->normalizeDayKey($dayKey);
            if ($day === null) {
                $errors["working_hours.{$dayKey}"] = [__('branch.working_hours_invalid_day', ['day' => $dayKey])];

                continue;
            }

            if (! is_array($shifts)) {
                $errors["working_hours.{$day}"] = [__('branch.working_hours_day_must_be_array', ['day' => $day])];

                continue;
            }

            $errors = array_merge($errors, $this->validateShiftsList($shifts, "working_hours.{$day}"));
        }

        return $errors;
    }

    public function sync(Branch $branch, ?array $input): void
    {
        $schedule = $this->normalizeInput($input);

        BranchWorkingHourShift::where('branch_id', $branch->id)->delete();

        $rows = [];
        $now = now();

        foreach ($schedule as $day => $shifts) {
            foreach ($shifts as $index => $shift) {
                $rows[] = [
                    'branch_id' => $branch->id,
                    'day_of_week' => $day,
                    'start_time' => $this->toDbTime($shift['start_time']),
                    'end_time' => $this->toDbTime($shift['end_time']),
                    'sort_order' => $index,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($rows !== []) {
            BranchWorkingHourShift::insert($rows);
        }

        $branch->unsetRelation('workingHourShifts');
        Branch::clearCache($branch);
    }

    public function toApiFormat(Branch $branch): array
    {
        $schedule = $this->emptySchedule();

        $shifts = $branch->relationLoaded('workingHourShifts')
            ? $branch->workingHourShifts
            : $branch->workingHourShifts()->get();

        foreach ($shifts->groupBy('day_of_week') as $day => $dayShifts) {
            if (! isset($schedule[$day])) {
                continue;
            }

            $schedule[$day] = $dayShifts
                ->sortBy('sort_order')
                ->values()
                ->map(fn (BranchWorkingHourShift $shift) => [
                    'start_time' => $this->formatTime($shift->start_time),
                    'end_time' => $this->formatTime($shift->end_time),
                ])
                ->all();
        }

        return $schedule;
    }

    public function toDbTime(string $time): string
    {
        return Carbon::createFromFormat('H:i', $this->formatTime($time))->format('H:i:s');
    }

    public function formatTime(mixed $time): string
    {
        if ($time instanceof \DateTimeInterface) {
            return $time->format('H:i');
        }

        $value = trim((string) $time);

        if ($value === '') {
            return '00:00';
        }

        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $value, $matches)) {
            return sprintf('%02d:%02d', (int) $matches[1], (int) $matches[2]);
        }

        return substr($value, 0, 5);
    }

    private function normalizeDayKey(mixed $dayKey): ?string
    {
        $day = Str::lower(trim((string) $dayKey));

        return in_array($day, self::DAYS, true) ? $day : null;
    }

    /**
     * @return array<int, array{start_time: string, end_time: string}>
     */
    private function normalizeShiftsList(array $shifts): array
    {
        $normalized = [];

        foreach ($shifts as $shift) {
            if (! is_array($shift)) {
                continue;
            }

            $start = $shift['start_time'] ?? $shift['from'] ?? null;
            $end = $shift['end_time'] ?? $shift['to'] ?? null;

            if ($start === null || $end === null) {
                continue;
            }

            $normalized[] = [
                'start_time' => $this->formatTime($start),
                'end_time' => $this->formatTime($end),
            ];
        }

        return $normalized;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function validateShiftsList(array $shifts, string $prefix): array
    {
        $errors = [];

        foreach ($shifts as $index => $shift) {
            if (! is_array($shift)) {
                $errors["{$prefix}.{$index}"] = [__('branch.working_hours_shift_invalid')];

                continue;
            }

            $start = $shift['start_time'] ?? $shift['from'] ?? null;
            $end = $shift['end_time'] ?? $shift['to'] ?? null;

            if ($start === null || $start === '') {
                $errors["{$prefix}.{$index}.start_time"] = [__('branch.working_hours_start_required')];
            } elseif (! $this->isValidTime($start)) {
                $errors["{$prefix}.{$index}.start_time"] = [__('branch.working_hours_invalid_time')];
            }

            if ($end === null || $end === '') {
                $errors["{$prefix}.{$index}.end_time"] = [__('branch.working_hours_end_required')];
            } elseif (! $this->isValidTime($end)) {
                $errors["{$prefix}.{$index}.end_time"] = [__('branch.working_hours_invalid_time')];
            }

            if (
                $start !== null && $end !== null
                && $this->isValidTime($start)
                && $this->isValidTime($end)
                && $this->formatTime($start) >= $this->formatTime($end)
            ) {
                $errors["{$prefix}.{$index}.end_time"] = [__('branch.working_hours_end_after_start')];
            }
        }

        return $errors;
    }

    private function isValidTime(mixed $time): bool
    {
        if (! is_string($time) && ! is_numeric($time)) {
            return false;
        }

        return (bool) preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', trim((string) $time));
    }
}
