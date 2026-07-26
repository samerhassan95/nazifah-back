<?php

namespace Modules\Vendor\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Branch\Models\Branch;

class VendorBranchFilter
{
    /**
     * Validation rules for optional branch_id / branch_ids query params.
     *
     * @return array<string, mixed>
     */
    public static function validationRules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['integer', 'exists:branches,id'],
        ];
    }

    /**
     * Resolve vendor-owned branch IDs from branch_id or branch_ids request params.
     * Returns all vendor branches when no filter is provided.
     */
    public static function resolveIds(Request $request, int $vendorId): Collection
    {
        $query = Branch::where('vendor_id', $vendorId);

        $requestedIds = self::requestedIds($request);
        if ($requestedIds !== null) {
            $query->whereIn('id', $requestedIds);
        }

        return $query->pluck('id');
    }

    /**
     * Normalize branch_ids from comma-separated string to array (e.g. branch_ids=19,24).
     */
    public static function normalizeRequest(Request $request): void
    {
        if (! $request->has('branch_ids')) {
            return;
        }

        $ids = $request->input('branch_ids');

        if (is_string($ids)) {
            $request->merge([
                'branch_ids' => array_values(array_filter(array_map(
                    fn ($id) => (int) trim($id),
                    explode(',', $ids)
                ))),
            ]);
        }
    }

    /**
     * @return int[]|null Null when no branch filter was requested.
     */
    public static function requestedIds(Request $request): ?array
    {
        self::normalizeRequest($request);

        if ($request->has('branch_ids')) {
            $ids = $request->input('branch_ids');

            if (is_string($ids)) {
                $ids = array_values(array_filter(array_map(
                    fn ($id) => (int) trim($id),
                    explode(',', $ids)
                )));
            } elseif (is_array($ids)) {
                $ids = array_values(array_filter(array_map('intval', $ids)));
            } else {
                $ids = [];
            }

            return $ids;
        }

        if ($request->filled('branch_id')) {
            return [(int) $request->branch_id];
        }

        return null;
    }

    public static function hasFilter(Request $request): bool
    {
        return self::requestedIds($request) !== null;
    }
}
