<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Services\LaundryCatalogImportService;

class AdminLaundryCatalogImportController extends Controller
{
    public function __construct(
        private LaundryCatalogImportService $catalogImportService
    ) {}

    /**
     * Import the Nathefah catalog Excel template.
     *
     * scope=categories - only sheet 01 (global, no vendor/branch needed)
     * scope=services   - only sheet 02 (global, matched against existing categories)
     * scope=full       - everything (categories/services/pieces/additional services),
     *                     pieces+additional services created for vendor_id and linked
     *                     to every branch in branch_ids
     *
     * POST /laundries/catalog-import
     */
    public function import(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls'],
            'scope' => ['sometimes', 'in:categories,services,full'],
            'vendor_id' => ['required_if:scope,full', 'nullable', 'exists:vendors,id'],
            'branch_ids' => ['required_if:scope,full', 'nullable', 'array', 'min:1'],
            'branch_ids.*' => ['exists:branches,id'],
            'dry_run' => ['sometimes', 'boolean'],
        ]);

        $scope = $validated['scope'] ?? 'full';
        $dryRun = $request->boolean('dry_run', false);

        try {
            $stats = $this->catalogImportService->import(
                $validated['file']->getRealPath(),
                isset($validated['vendor_id']) ? (int) $validated['vendor_id'] : null,
                isset($validated['branch_ids']) ? array_map('intval', $validated['branch_ids']) : [],
                $dryRun,
                $scope
            );
        } catch (\Throwable $e) {
            return errorResponse($e->getMessage(), null, 422);
        }

        return successResponse(
            $stats,
            $dryRun ? 'Dry run completed - no changes were made' : 'Catalog imported successfully'
        );
    }
}
