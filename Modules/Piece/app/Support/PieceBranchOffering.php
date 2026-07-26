<?php

namespace Modules\Piece\Support;

use Illuminate\Support\Facades\DB;
use Modules\Piece\Models\Piece;

class PieceBranchOffering
{
    /**
     * @return array<int, object> piece_id => branch_piece row
     */
    public static function pivotMapForPieces(int $branchId, array $pieceIds): array
    {
        if ($pieceIds === []) {
            return [];
        }

        return DB::table('branch_piece')
            ->where('branch_id', $branchId)
            ->whereIn('piece_id', $pieceIds)
            ->get()
            ->keyBy('piece_id')
            ->all();
    }

    /**
     * Standard branch-scoped piece name fields for API responses.
     *
     * @return array{
     *     branch_id: int,
     *     name: array{ar: string, en: string},
     *     display_name: string,
     *     catalog_name: array{ar: string, en: string},
     *     has_custom_name: bool
     * }
     */
    public static function branchApiFields(
        Piece $piece,
        int $branchId,
        string $lang,
        ?object $pivotRow = null,
        bool $localizedNameOnly = false
    ): array {
        $pivotRow ??= self::find($branchId, (int) $piece->id);
        $names = self::displayNameArrayFromPivot($piece, $pivotRow);
        $displayName = $names[$lang] ?? $names['ar'] ?? $names['en'] ?? '';

        $fields = [
            'branch_id' => $branchId,
            'has_custom_name' => self::hasBranchNameOverrideFromPivot($pivotRow),
            ...\App\Support\CatalogActivePresenter::piece($piece, $branchId, $pivotRow),
        ];

        if ($localizedNameOnly) {
            $fields['name'] = $displayName;
        } else {
            $fields['name'] = $names;
            $fields['display_name'] = $displayName;
            $fields['catalog_name'] = self::catalogNameArray($piece);
        }

        return $fields;
    }

    /**
     * @return array{ar: string, en: string}
     */
    public static function catalogNameArray(Piece $piece): array
    {
        return [
            'ar' => $piece->getTranslation('name', 'ar', false) ?: '',
            'en' => $piece->getTranslation('name', 'en', false) ?: '',
        ];
    }

    /**
     * @return array{ar: string, en: string}
     */
    public static function displayNameArrayFromPivot(Piece $piece, ?object $pivotRow): array
    {
        if ($pivotRow && $pivotRow->name) {
            $decoded = is_string($pivotRow->name) ? json_decode($pivotRow->name, true) : (array) $pivotRow->name;
            if (is_array($decoded)) {
                return [
                    'ar' => (string) ($decoded['ar'] ?? $decoded['en'] ?? ''),
                    'en' => (string) ($decoded['en'] ?? $decoded['ar'] ?? ''),
                ];
            }
        }

        return self::catalogNameArray($piece);
    }

    public static function hasBranchNameOverrideFromPivot(?object $pivotRow): bool
    {
        return $pivotRow !== null && ! empty($pivotRow->name);
    }

    /**
     * Upsert branch-specific display name for a catalog piece (does not edit pieces.name).
     *
     * @param  array{ar?: string, en?: string}|null  $name
     */
    public static function upsert(int $branchId, Piece $piece, ?array $name): void
    {
        if ($name === null) {
            return;
        }

        $row = [
            'name' => json_encode([
                'ar' => $name['ar'] ?? $name['en'] ?? '',
                'en' => $name['en'] ?? $name['ar'] ?? '',
            ]),
            'updated_at' => now(),
        ];

        $existing = self::find($branchId, $piece->id);

        if ($existing) {
            DB::table('branch_piece')
                ->where('id', $existing->id)
                ->update($row);
        } else {
            DB::table('branch_piece')->insert([
                'branch_id' => $branchId,
                'piece_id' => $piece->id,
                'name' => $row['name'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public static function find(int $branchId, int $pieceId): ?object
    {
        return DB::table('branch_piece')
            ->where('branch_id', $branchId)
            ->where('piece_id', $pieceId)
            ->first();
    }

    public static function displayNameForBranch(Piece $piece, int $branchId, string $lang): string
    {
        $names = self::displayNameArrayForBranch($piece, $branchId);

        return $names[$lang] ?? $names['ar'] ?? $names['en'] ?? '';
    }

    /**
     * @return array{ar: string, en: string}
     */
    public static function displayNameArrayForBranch(Piece $piece, int $branchId): array
    {
        return self::displayNameArrayFromPivot($piece, self::find($branchId, (int) $piece->id));
    }

    public static function hasBranchNameOverride(int $branchId, int $pieceId): bool
    {
        return self::hasBranchNameOverrideFromPivot(self::find($branchId, $pieceId));
    }
}
