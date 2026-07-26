<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Branch\Services\BranchWorkingHoursService;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_working_hour_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('day_of_week', 10);
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['branch_id', 'day_of_week']);
        });

        if (! Schema::hasColumn('branches', 'working_hours')) {
            return;
        }

        $service = app(BranchWorkingHoursService::class);

        DB::table('branches')
            ->whereNotNull('working_hours')
            ->orderBy('id')
            ->chunkById(100, function ($branches) use ($service) {
                $rows = [];
                $now = now();

                foreach ($branches as $branchRow) {
                    $legacy = json_decode($branchRow->working_hours, true);
                    if (! is_array($legacy) || $legacy === []) {
                        continue;
                    }

                    $schedule = $service->normalizeInput($legacy);

                    foreach ($schedule as $day => $shifts) {
                        foreach ($shifts as $index => $shift) {
                            $rows[] = [
                                'branch_id' => $branchRow->id,
                                'day_of_week' => $day,
                                'start_time' => $service->toDbTime($shift['start_time']),
                                'end_time' => $service->toDbTime($shift['end_time']),
                                'sort_order' => $index,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                        }
                    }
                }

                if ($rows !== []) {
                    DB::table('branch_working_hour_shifts')->insert($rows);
                }
            });

        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('working_hours');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            if (! Schema::hasColumn('branches', 'working_hours')) {
                $table->json('working_hours')->nullable()->after('description');
            }
        });

        if (Schema::hasTable('branch_working_hour_shifts')) {
            $service = app(BranchWorkingHoursService::class);

            DB::table('branch_working_hour_shifts')
                ->select('branch_id')
                ->distinct()
                ->orderBy('branch_id')
                ->pluck('branch_id')
                ->each(function ($branchId) use ($service) {
                    $shifts = DB::table('branch_working_hour_shifts')
                        ->where('branch_id', $branchId)
                        ->orderBy('day_of_week')
                        ->orderBy('sort_order')
                        ->get();

                    $schedule = $service->emptySchedule();
                    foreach ($shifts->groupBy('day_of_week') as $day => $dayShifts) {
                        if (! isset($schedule[$day])) {
                            continue;
                        }
                        $schedule[$day] = $dayShifts->map(fn ($shift) => [
                            'start_time' => $service->formatTime($shift->start_time),
                            'end_time' => $service->formatTime($shift->end_time),
                        ])->values()->all();
                    }

                    $legacy = [];
                    foreach ($schedule as $dayShifts) {
                        if ($dayShifts !== []) {
                            $legacy = $dayShifts;
                            break;
                        }
                    }

                    DB::table('branches')
                        ->where('id', $branchId)
                        ->update([
                            'working_hours' => $legacy !== [] ? json_encode($legacy) : null,
                        ]);
                });
        }

        Schema::dropIfExists('branch_working_hour_shifts');
    }
};
