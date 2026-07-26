<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\UploadFilesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Branch\Models\Branch;
use Modules\Vendor\Models\Vendor;

class AdminLaundryBranchController extends Controller
{
    protected $uploadFilesService;

    public function __construct(UploadFilesService $uploadFilesService)
    {
        $this->uploadFilesService = $uploadFilesService;
    }

    /**
     * Display a listing of branches for a specific vendor.
     * GET /admin/laundries/branches?vendor_id=XX
     */
    public function index(Request $request): JsonResponse
    {
        $vendorId = $request->input('vendor_id');
        $perPage = $request->input('per_page', 15);
        $lang = app()->getLocale();

        if (! $vendorId) {
            return errorResponse('Vendor ID is required', null, 400);
        }

        $vendor = Vendor::find($vendorId);
        if (! $vendor) {
            return notFoundResponse('Vendor not found');
        }

        $branches = Branch::where('vendor_id', $vendorId)
            ->with(['vendor'])
            ->latest()
            ->paginate($perPage);

        // Header Stats for the Vendor
        $states = [
            'vendor_id' => $vendor->id,
            'vendor_name' => $vendor->getTranslation('name', $lang),
            'total_branches' => $branches->total(),
            'active_branches' => Branch::where('vendor_id', $vendorId)->where('is_active', true)->count(),
        ];

        $branchesData = collect($branches->items())->map(fn ($branch) => $this->formatBranch($branch));

        return successResponse([
            'States' => $states,
            'Branches' => $branchesData,
            'pagination' => [
                'total' => $branches->total(),
                'per_page' => $branches->perPage(),
                'current_page' => $branches->currentPage(),
                'last_page' => $branches->lastPage(),
            ],
        ], 'Branches retrieved successfully');
    }

    /**
     * Store a newly created branch.
     * POST /admin/laundries/branches
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vendor_id' => 'required|exists:vendors,id',
            'Branch_logo' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'name' => 'required|array',
            'name.ar' => 'required|string',
            'name.en' => 'required|string',
            'description' => 'nullable|array',
            'description.ar' => 'nullable|string',
            'description.en' => 'nullable|string',
            'address' => 'required|array',
            'address.ar' => 'required|string',
            'address.en' => 'required|string',
            'Phone' => 'required|string',
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
        ]);

        $branch = new Branch;
        $branch->vendor_id = $validated['vendor_id'];

        // Handle Translations
        $branch->setTranslation('name', 'ar', $validated['name']['ar']);
        $branch->setTranslation('name', 'en', $validated['name']['en']);
        $branch->setTranslation('location', 'ar', $validated['address']['ar']);
        $branch->setTranslation('location', 'en', $validated['address']['en']);
        $branch->setTranslation('description', 'ar', $validated['description']['ar']);
        $branch->setTranslation('description', 'en', $validated['description']['en']);

        $branch->phone_number = $validated['Phone'];
        $branch->is_active = true;

        if ($request->hasFile('Branch_logo')) {
            $branch->store_front = $this->uploadFilesService->uploadImage(
                $request->file('Branch_logo'), 'branches/logos'
            );
        }

        $branch->save();

        return successResponse($this->formatBranch($branch->load('vendor')), 'Branch created successfully', 201);
    }

    /**
     * Display the specified branch.
     * GET /admin/laundries/branches/{id}
     */
    public function show(int $id): JsonResponse
    {
        $branch = Branch::with('vendor')->find($id);

        if (! $branch) {
            return notFoundResponse('Branch not found');
        }

        return successResponse($this->formatBranch($branch), 'Branch retrieved successfully');
    }

    /**
     * Update the specified branch.
     * PUT/PATCH /admin/laundries/branches/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $branch = Branch::find($id);
        if (! $branch) {
            return notFoundResponse('Branch not found');
        }

        $validated = $request->validate([
            'Branch_logo' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'name' => 'sometimes|array',
            'name.ar' => 'sometimes|string',
            'name.en' => 'sometimes|string',
            'address' => 'sometimes|array',
            'address.ar' => 'sometimes|string',
            'address.en' => 'sometimes|string',
            'description' => 'sometimes|array',
            'description.ar' => 'sometimes|string',
            'description.en' => 'sometimes|string',
            'Phone' => 'sometimes|string',
            'is_active' => 'sometimes|boolean',
        ]);

        // Dynamic Translation Updates
        if ($request->has('name')) {
            $branch->setTranslation('name', 'ar', $validated['name']['ar']);
        }
        if ($request->has('name')) {
            $branch->setTranslation('name', 'en', $validated['name']['en']);
        }
        if ($request->has('address')) {
            $branch->setTranslation('location', 'ar', $validated['address']['ar']);
        }
        if ($request->has('address')) {
            $branch->setTranslation('location', 'en', $validated['address']['en']);
        }
        if ($request->has('description')) {
            $branch->setTranslation('description', 'ar', $validated['description']['ar']);
        }
        if ($request->has('description')) {
            $branch->setTranslation('description', 'en', $validated['description']['en']);
        }

        if ($request->has('Phone')) {
            $branch->phone_number = $validated['Phone'];
        }
        if ($request->has('is_active')) {
            $branch->is_active = $validated['is_active'];
        }

        if ($request->hasFile('Branch_logo')) {
            $branch->store_front = $this->uploadFilesService->uploadImage(
                $request->file('Branch_logo'), 'branches/logos', $branch->store_front
            );
        }

        $branch->save();

        return successResponse($this->formatBranch($branch->load('vendor')), 'Branch updated successfully');
    }

    /**
     * Toggle branch activation status
     * PATCH /admin/laundries/branches/{id}/toggle
     */
    public function toggleStatus(int $id): JsonResponse
    {
        $branch = Branch::find($id);
        if (! $branch) {
            return notFoundResponse('Branch not found');
        }

        $branch->is_active = ! $branch->is_active;
        $branch->save();

        return successResponse([
            'id' => $branch->id,
            'is_active' => $branch->is_active,
        ], 'Branch status toggled successfully');
    }

    /**
     * Remove the specified branch.
     * DELETE /admin/laundries/branches/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $branch = Branch::find($id);
        if (! $branch) {
            return notFoundResponse('Branch not found');
        }

        // Optional: Check for existing orders before deleting
        if ($branch->orders()->exists()) {
            return errorResponse('Cannot delete branch with existing orders. Deactivate it instead.', null, 400);
        }

        $branch->delete();

        return successResponse(null, 'Branch deleted successfully');
    }

    /**
     * Standardized Formatter for Branch Data
     */
    private function formatBranch($branch): array
    {
        $lang = app()->getLocale();

        return [
            'id' => $branch->id,
            'Vendor' => [
                'id' => $branch->vendor_id,
                'name' => $branch->vendor ? $branch->vendor->getTranslation('name', $lang) : null,
            ],
            'Branch_logo' => $branch->store_front ? (str_starts_with($branch->store_front, 'http') ? $branch->store_front : config('app.url').$branch->store_front) : null,
            'name' => [
                'ar' => $branch->getTranslation('name', 'ar'),
                'en' => $branch->getTranslation('name', 'en'),
                'current' => $branch->getTranslation('name', $lang),
            ],
            'description' => [
                'ar' => $branch->getTranslation('description', 'ar'),
                'en' => $branch->getTranslation('description', 'en'),
                'current' => $branch->getTranslation('description', $lang),
            ],
            'address' => [
                'ar' => $branch->getTranslation('location', 'ar'),
                'en' => $branch->getTranslation('location', 'en'),
                'current' => $branch->getTranslation('location', $lang),
            ],
            'Phone' => $branch->phone_number,
            'Is_Active' => (bool) $branch->is_active,
            'Created_at' => $branch->created_at ? $branch->created_at->format('Y-m-d') : null,
        ];
    }
}
