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
     * Import the Nathefah catalog Excel template (categories/services globally,
     * pieces/additional services for the given vendor, then links everything to
     * the given branches).
     *
     * POST /laundries/catalog-import
     */
    public function import(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls'],
            'vendor_id' => ['required', 'exists:vendors,id'],
            'branch_ids' => ['required', 'array', 'min:1'],
            'branch_ids.*' => ['exists:branches,id'],
            'dry_run' => ['sometimes', 'boolean'],
        ]);

        $dryRun = $request->boolean('dry_run', false);

        try {
            $stats = $this->catalogImportService->import(
                $validated['file']->getRealPath(),
                (int) $validated['vendor_id'],
                array_map('intval', $validated['branch_ids']),
                $dryRun
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
