<?php

namespace Modules\Service\Support;

use Illuminate\Support\Facades\DB;
use Modules\Service\Models\ServiceAddition;

class ServiceAdditionBranchOffering
{
    /**
     * Upsert branch-specific name/price for a catalog service addition (does not edit service_additions).
     *
     * @param  array{name?: array{ar?: string, en?: string}|null, price: float, icon_id?: int|null}  $data
     */
    public static function upsert(int $branchId, ServiceAddition $addition, array $data): void
    {
        $row = [
            'branch_id' => $branchId,
            'service_addition_id' => $addition->id,
            'price' => (float) $data['price'],
            'updated_at' => now(),
        ];

        if (array_key_exists('name', $data) && $data['name'] !== null) {
            $name = $data['name'];
            $row['name'] = json_encode([
                'ar' => $name['ar'] ?? $name['en'] ?? '',
                'en' => $name['en'] ?? $name['ar'] ?? '',
            ]);
        }

        if (array_key_exists('icon_id', $data)) {
            $row['icon_id'] = $data['icon_id'];
        }

        $existing = DB::table('branch_service_addition')
            ->where('branch_id', $branchId)
            ->where('service_addition_id', $addition->id)
            ->first();

        if ($existing) {
            if (! array_key_exists('name', $row) && $existing->name !== null) {
                unset($row['name']);
            }
            if (! array_key_exists('icon_id', $row) && $existing->icon_id !== null) {
                unset($row['icon_id']);
            }
            if (! array_key_exists('is_active', $row)) {
                $row['is_active'] = $existing->is_active ?? true;
            }
            DB::table('branch_service_addition')
                ->where('id', $existing->id)
                ->update($row);
        } else {
            $row['created_at'] = now();
            if (! array_key_exists('name', $row)) {
                $row['name'] = null;
            }
            if (! array_key_exists('icon_id', $row)) {
                $row['icon_id'] = null;
            }
            $row['is_active'] = $data['is_active'] ?? true;
            DB::table('branch_service_addition')->insert($row);
        }

        if (array_key_exists('price', $data)) {
            DB::table('service_addition_piece')
                ->where('branch_id', $branchId)
                ->where('service_addition_id', $addition->id)
                ->update([
                    'price' => (float) $data['price'],
                    'updated_at' => now(),
                ]);
        }
    }

    public static function find(int $branchId, int $serviceAdditionId): ?object
    {
        return DB::table('branch_service_addition')
            ->where('branch_id', $branchId)
            ->where('service_addition_id', $serviceAdditionId)
            ->first();
    }

    public static function priceForBranch(int $branchId, ServiceAddition $addition): ?float
    {
        $row = self::find($branchId, $addition->id);

        return $row && $row->price !== null ? (float) $row->price : null;
    }

    public static function displayNameForBranch(ServiceAddition $addition, int $branchId, string $lang): string
    {
        $names = self::displayNameArrayForBranch($addition, $branchId);

        return $names[$lang] ?? $names['ar'] ?? $names['en'] ?? '';
    }

    /**
     * @return array{ar: string, en: string}
     */
    public static function displayNameArrayForBranch(ServiceAddition $addition, int $branchId): array
    {
        $row = self::find($branchId, $addition->id);

        if ($row && $row->name) {
            $decoded = is_string($row->name) ? json_decode($row->name, true) : (array) $row->name;
            if (is_array($decoded)) {
                return [
                    'ar' => (string) ($decoded['ar'] ?? $decoded['en'] ?? ''),
                    'en' => (string) ($decoded['en'] ?? $decoded['ar'] ?? ''),
                ];
            }
        }

        return [
            'ar' => $addition->getTranslation('name', 'ar', false) ?: '',
            'en' => $addition->getTranslation('name', 'en', false) ?: '',
        ];
    }

    public static function hasBranchNameOverride(int $branchId, int $serviceAdditionId): bool
    {
        $row = self::find($branchId, $serviceAdditionId);

        return $row !== null && ! empty($row->name);
    }

    public static function iconIdForBranch(int $branchId, ServiceAddition $addition): ?int
    {
        $row = self::find($branchId, $addition->id);

        if ($row && $row->icon_id) {
            return (int) $row->icon_id;
        }

        return $addition->icon_id ? (int) $addition->icon_id : null;
    }

    /**
     * Remove addition from one branch (branch offering + all piece links at that branch).
     * Does not delete service_additions catalog or other branches.
     */
    public static function removeFromBranch(int $branchId, int $serviceAdditionId): int
    {
        $pieceLinksRemoved = DB::table('service_addition_piece')
            ->where('branch_id', $branchId)
            ->where('service_addition_id', $serviceAdditionId)
            ->delete();

        DB::table('branch_service_addition')
            ->where('branch_id', $branchId)
            ->where('service_addition_id', $serviceAdditionId)
            ->delete();

        return $pieceLinksRemoved;
    }

    /**
     * Remove addition from one piece at one branch only.
     */
    public static function removeFromPieceAtBranch(int $branchId, int $pieceId, int $serviceAdditionId): bool
    {
        return DB::table('service_addition_piece')
            ->where('branch_id', $branchId)
            ->where('piece_id', $pieceId)
            ->where('service_addition_id', $serviceAdditionId)
            ->delete() > 0;
    }

    /**
     * Link addition to one piece at one branch only (does not touch other branches or catalog).
     */
    public static function linkToPiece(int $branchId, int $pieceId, ServiceAddition $addition, float $price): void
    {
        DB::table('service_addition_piece')->upsert(
            [[
                'service_addition_id' => $addition->id,
                'piece_id' => $pieceId,
                'branch_id' => $branchId,
                'price' => $price,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['service_addition_id', 'piece_id', 'branch_id'],
            ['price', 'updated_at']
        );
    }
}
