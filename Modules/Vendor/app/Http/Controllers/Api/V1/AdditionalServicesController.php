<?php

namespace Modules\Vendor\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\UploadFilesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\Admin\Models\Icon;
use Modules\Branch\Models\Branch;
use Modules\Order\Models\Order;
use Modules\Service\Models\ServiceAddition;
use Modules\Service\Support\ServiceAdditionBranchOffering;
use Modules\Vendor\Models\Vendor;

class AdditionalServicesController extends Controller
{
    protected $uploadFilesService;

    public function __construct(UploadFilesService $uploadFilesService)
    {
        $this->uploadFilesService = $uploadFilesService;
    }

    /**
     * Get additional services
     */
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'piece_id' => ['nullable', 'integer', 'exists:pieces,id'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $employee = $request->user();
        $vendorId = $employee->vendor_id;
        $branchId = $request->input('branch_id');
        $pieceId = $request->input('piece_id');

        if ($branchId) {
            $branch = \Modules\Branch\Models\Branch::where('id', $branchId)
                ->where('vendor_id', $vendorId)
                ->first();
            if (! $branch) {
                return notFoundResponse(__('branch.branch_not_found_or_not_yours'));
            }
        }

        // Start with base query
        $query = ServiceAddition::where('vendor_id', $vendorId);

        // If branch_id is provided, filter by branch offering or piece links at that branch
        if ($branchId && ! $pieceId) {
            $query->where(function ($q) use ($branchId) {
                $q->whereHas('branches', function ($bq) use ($branchId) {
                    $bq->where('branches.id', $branchId);
                })->orWhereHas('pieces', function ($pq) use ($branchId) {
                    $pq->where('service_addition_piece.branch_id', $branchId);
                });
            });
        }

        // If piece_id is provided, filter by piece relationship
        if ($pieceId) {
            $query->whereHas('pieces', function ($q) use ($pieceId, $branchId) {
                $q->where('pieces.id', $pieceId);

                // If both piece_id and branch_id are provided, filter by branch too
                if ($branchId) {
                    $q->where('service_addition_piece.branch_id', $branchId);
                }
            });
        }

        $services = $query->get()
            ->map(fn ($service) => $this->formatAdditionalServiceRow($service, $branchId, $pieceId));

        return successResponse($services, 'Additional services retrieved successfully');
    }

    /**
     * Vendor catalog additional services not yet offered at this branch (for add-to-branch dropdowns).
     * GET /api/v1/vendor/branches/{branchId}/available-additional-services
     */
    public function availableForBranchById(Request $request, $branchId): JsonResponse
    {
        return $this->respondWithAdditionalServicesAvailableForBranch($request, (int) $branchId);
    }

    /**
     * Add additional service
     * piece_id is optional: when omitted, the additional service is created at vendor level only.
     * Assign it to pieces later via POST /api/v1/vendor/pieces/{pieceId}/additional-services
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required_without_all:name.ar,name.en'],
            'name.ar' => ['required_without:name', 'string', 'max:255'],
            'name.en' => ['required_without:name', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'piece_id' => ['nullable', 'exists:pieces,id'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'icon_id' => ['required', 'exists:icons,id'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $employee = $request->user();
        $vendorId = $employee->vendor_id;

        // When piece_id provided, ensure piece belongs to vendor
        if ($request->filled('piece_id')) {
            $piece = \Modules\Piece\Models\Piece::where('id', $request->piece_id)
                ->where('vendor_id', $vendorId)
                ->first();
            if (! $piece) {
                return notFoundResponse(__('piece.piece_not_found'));
            }
        }

        // When branch_id provided, ensure branch belongs to vendor
        if ($request->filled('branch_id')) {
            $branch = \Modules\Branch\Models\Branch::where('id', $request->branch_id)
                ->where('vendor_id', $vendorId)
                ->first();
            if (! $branch) {
                return notFoundResponse(__('branch.branch_not_found_or_not_yours'));
            }
        }

        // Handle name - support both formats
        $name = [];
        if ($request->has('name') && is_string($request->name)) {
            $name = ['ar' => $request->name, 'en' => $request->name];
        } elseif ($request->has('name') && is_array($request->name)) {
            $name = [
                'ar' => $request->name['ar'] ?? $request->name['en'] ?? '',
                'en' => $request->name['en'] ?? $request->name['ar'] ?? '',
            ];
        }

        $service = ServiceAddition::create([
            'vendor_id' => $vendorId,
            'name' => $name,
            'price' => $request->price,
            'icon_id' => $request->icon_id ?? null,
        ]);

        // Attach to piece only when piece_id is provided (vendor-level otherwise)
        if ($request->filled('piece_id')) {
            $service->pieces()->attach($request->piece_id, [
                'price' => $request->price,
                'branch_id' => $request->branch_id, // null = vendor-level, or specific branch
            ]);

            // cache invalidation: additional service attached to piece
            if ($request->filled('branch_id')) {
                flushCacheTags(["branch_{$request->branch_id}", 'services', 'branches']);
            } else {
                flushCacheTags(['services', 'branches']);
            }
        }

        // Load icon relation
        $service->load('iconRelation');

        $response = [
            'id' => $service->id,
            'name' => [
                'ar' => $service->getTranslation('name', 'ar', false) ?: '',
                'en' => $service->getTranslation('name', 'en', false) ?: '',
            ],
            'price' => (float) $service->price,
            'icon_id' => $service->icon_id,
            'icon' => $service->iconRelation ? $service->iconRelation->full_path : null,
            'created_at' => $service->created_at?->format('Y-m-d H:i:s'),
        ];
        if ($request->filled('piece_id')) {
            $response['piece_id'] = (int) $request->piece_id;
            $response['branch_id'] = $request->filled('branch_id') ? (int) $request->branch_id : null;
        }

        return successResponse($response, 'Additional service added successfully', 201);
    }

    /**
     * Get single additional service
     */
    public function show(Request $request, $serviceId): JsonResponse
    {
        $employee = $request->user();
        $vendorId = $employee->vendor_id;

        $service = ServiceAddition::where('id', $serviceId)
            ->where('vendor_id', $vendorId)
            ->first();

        if (! $service) {
            return notFoundResponse('Additional service not found');
        }

        // Load icon relation
        $service->load('iconRelation');

        // Calculate rating from orders that include this additional service
        $rating = Order::whereHas('items.additionalServicesRelation', function ($query) use ($service) {
            $query->where('service_additions.id', $service->id);
        })
            ->whereNotNull('rating')
            ->avg('rating') ?? 0;

        return successResponse([
            'id' => $service->id,
            'name' => [
                'ar' => $service->getTranslation('name', 'ar', false) ?: '',
                'en' => $service->getTranslation('name', 'en', false) ?: '',
            ],
            'price' => (float) $service->price,
            'icon_id' => $service->icon_id,
            'icon' => $service->iconRelation ? $this->uploadFilesService->getFullUrl($service->iconRelation->path) : null,
            'is_active' => (bool) $service->is_active,
            'rating' => round((float) $rating, 2),
            'created_at' => $service->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $service->updated_at?->format('Y-m-d H:i:s'),
        ], 'Additional service retrieved successfully');
    }

    /**
     * Update additional service
     */
    public function update(Request $request, $serviceId): JsonResponse
    {
        $employee = $request->user();
        $vendorId = $employee->vendor_id;

        $service = ServiceAddition::where('id', $serviceId)
            ->where('vendor_id', $vendorId)
            ->first();

        if (! $service) {
            return notFoundResponse('Additional service not found');
        }

        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'required_without_all:name.ar,name.en'],
            'name.ar' => ['sometimes', 'string', 'max:255'],
            'name.en' => ['sometimes', 'string', 'max:255'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'piece_id' => ['sometimes', 'exists:pieces,id'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'icon_id' => ['nullable', 'exists:icons,id'],
            'update_all_branches' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $catalogData = [];

        if ($request->has('name') && ! $request->filled('branch_id')) {
            if (is_string($request->name)) {
                $catalogData['name'] = ['ar' => $request->name, 'en' => $request->name];
            } elseif (is_array($request->name)) {
                $catalogData['name'] = [
                    'ar' => $request->name['ar'] ?? $request->name['en'] ?? '',
                    'en' => $request->name['en'] ?? $request->name['ar'] ?? '',
                ];
            }
        }

        if ($request->has('price') && ! $request->filled('branch_id') && ! $request->boolean('update_all_branches')) {
            $catalogData['price'] = $request->price;
        }

        if ($request->has('icon_id') && ! $request->filled('branch_id')) {
            $catalogData['icon_id'] = $request->icon_id;
        }

        if (! empty($catalogData)) {
            $service->update($catalogData);
        }

        // Update one branch offering only (does not change catalog or other vendors' branches)
        if ($request->filled('branch_id') && $request->has('price')) {
            $branch = \Modules\Branch\Models\Branch::where('id', $request->branch_id)
                ->where('vendor_id', $vendorId)
                ->first();

            if (! $branch) {
                return notFoundResponse(__('branch.branch_not_found_or_not_yours'));
            }

            \Modules\Service\Support\ServiceAdditionBranchOffering::upsert((int) $branch->id, $service, [
                'price' => (float) $request->price,
                'name' => $request->has('name') ? $request->name : null,
                'icon_id' => $request->has('icon_id') ? $request->icon_id : null,
            ]);

            flushCacheTags(["branch_{$branch->id}", 'services', 'branches']);
        }

        // Update branch offerings for all of this vendor's branches only
        if ($request->boolean('update_all_branches') && $request->has('price')) {
            $branchIds = \Modules\Branch\Models\Branch::where('vendor_id', $vendorId)->pluck('id');

            foreach ($branchIds as $bid) {
                \Modules\Service\Support\ServiceAdditionBranchOffering::upsert((int) $bid, $service, [
                    'price' => (float) $request->price,
                ]);
            }

            DB::table('service_addition_piece')
                ->where('service_addition_id', $service->id)
                ->whereIn('branch_id', $branchIds)
                ->update(['price' => $request->price, 'updated_at' => now()]);

            flushCacheTags(['services', 'branches']);
        }

        if ($request->has('piece_id') && $request->filled('branch_id') && $request->has('price')) {
            $branch = \Modules\Branch\Models\Branch::where('id', $request->branch_id)
                ->where('vendor_id', $vendorId)
                ->first();

            if ($branch) {
                \Modules\Service\Support\ServiceAdditionBranchOffering::linkToPiece(
                    (int) $branch->id,
                    (int) $request->piece_id,
                    $service,
                    (float) $request->price
                );
                flushCacheTags(["branch_{$branch->id}", 'services', 'branches']);
            }
        }

        $service->refresh();
        $service->load('iconRelation');

        return successResponse([
            'id' => $service->id,
            'name' => [
                'ar' => $service->getTranslation('name', 'ar', false) ?: '',
                'en' => $service->getTranslation('name', 'en', false) ?: '',
            ],
            'price' => (float) $service->price,
            'icon_id' => $service->icon_id,
            'icon' => $service->iconRelation ? $this->uploadFilesService->getFullUrl($service->iconRelation->path) : null,
            'is_active' => (bool) $service->is_active,
            'updated_at' => $service->updated_at?->format('Y-m-d H:i:s'),
        ], 'Additional service updated successfully');
    }

    /**
     * Delete additional service from vendor catalog (all branches and piece links).
     * DELETE /api/v1/vendor/additional-services/{id}
     * To remove from one branch only, use DELETE /vendor/branches/{branchId}/additional-services/{id}
     */
    public function destroy(Request $request, $serviceId): JsonResponse
    {
        $employee = $request->user();
        $vendorId = $employee->vendor_id;

        $service = ServiceAddition::where('id', $serviceId)
            ->where('vendor_id', $vendorId)
            ->first();

        if (! $service) {
            return notFoundResponse('Additional service not found');
        }

        DB::table('service_addition_piece')
            ->where('service_addition_id', $service->id)
            ->delete();

        DB::table('branch_service_addition')
            ->where('service_addition_id', $service->id)
            ->delete();

        $service->delete();
        flushCacheTags(['services', 'branches', 'pieces']);

        return successResponse([
            'service_addition_id' => (int) $serviceId,
            'deleted_from_catalog' => true,
        ], __('service.service_addition_catalog_deleted'));
    }

    public function filter(Request $request): JsonResponse
    {
        $employee = $request->user();
        $vendorId = $employee->vendor_id;

        $validator = Validator::make($request->all(), [
            'name' => ['nullable', 'string'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $branchId = $request->input('branch_id');
        $query = ServiceAddition::where('vendor_id', $vendorId);

        if ($branchId) {
            $query->where(function ($q) use ($branchId) {
                $q->whereHas('branches', function ($bq) use ($branchId) {
                    $bq->where('branches.id', $branchId);
                })->orWhereHas('pieces', function ($pq) use ($branchId) {
                    $pq->where('service_addition_piece.branch_id', $branchId);
                });
            });
        }

        // Filter by name if provided
        if ($request->has('name') && $request->name) {
            $searchName = $request->name;
            $query->where(function ($q) use ($searchName) {
                $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(name, '$.ar')) LIKE ?", ["%{$searchName}%"])
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(name, '$.en')) LIKE ?", ["%{$searchName}%"]);
            });
        }

        $services = $query->get()
            ->map(fn ($service) => $this->formatAdditionalServiceRow($service, $branchId, null, includeRating: false));

        return successResponse($services, 'Filtered additional services retrieved successfully');
    }

    private function formatAdditionalServiceRow(
        ServiceAddition $service,
        ?int $branchId,
        ?int $pieceId,
        bool $includeRating = true
    ): array {
        $service->load('iconRelation');

        $branchOffering = $branchId ? ServiceAdditionBranchOffering::find($branchId, $service->id) : null;
        $lang = app()->getLocale();

        $catalogName = [
            'ar' => $service->getTranslation('name', 'ar', false) ?: '',
            'en' => $service->getTranslation('name', 'en', false) ?: '',
        ];

        $name = $branchId
            ? ServiceAdditionBranchOffering::displayNameArrayForBranch($service, $branchId)
            : $catalogName;

        $price = (float) ($service->price ?? 0);
        if ($branchId) {
            $branchPrice = ServiceAdditionBranchOffering::priceForBranch($branchId, $service);
            if ($branchPrice !== null) {
                $price = $branchPrice;
            }
        }

        $iconId = ($branchOffering && $branchOffering->icon_id)
            ? (int) $branchOffering->icon_id
            : $service->icon_id;

        $iconUrl = null;
        if ($branchOffering && $branchOffering->icon_id) {
            $branchIcon = Icon::find($branchOffering->icon_id);
            $iconUrl = $branchIcon ? $this->uploadFilesService->getFullUrl($branchIcon->path) : null;
        }
        if (! $iconUrl && $service->iconRelation) {
            $iconUrl = $this->uploadFilesService->getFullUrl($service->iconRelation->path);
        }

        $serviceData = array_merge([
            'id' => $service->id,
            'service_addition_id' => $service->id,
            'name' => $name,
            'display_name' => $name[$lang] ?? $name['ar'] ?? $name['en'] ?? '',
            'catalog_name' => $catalogName,
            'price' => $price,
            'icon_id' => $iconId,
            'icon' => $iconUrl,
            'created_at' => $service->created_at?->format('Y-m-d H:i:s'),
        ], \App\Support\CatalogActivePresenter::serviceAddition($service, $branchId, $branchOffering));

        if ($includeRating) {
            $rating = Order::whereHas('items.additionalServicesRelation', function ($query) use ($service) {
                $query->where('service_additions.id', $service->id);
            })
                ->whereNotNull('rating')
                ->avg('rating') ?? 0;
            $serviceData['rating'] = round((float) $rating, 2);
        }

        if ($branchId) {
            $serviceData['has_custom_name'] = ServiceAdditionBranchOffering::hasBranchNameOverride($branchId, $service->id);
            $branchPrice = ServiceAdditionBranchOffering::priceForBranch($branchId, $service);
            $serviceData['has_custom_price'] = $branchPrice !== null;
            if ($branchPrice !== null) {
                $serviceData['custom_price'] = $branchPrice;
            }
            $serviceData['branch_id'] = $branchId;
        }

        if ($pieceId) {
            $pivotQuery = DB::table('service_addition_piece')
                ->where('service_addition_id', $service->id)
                ->where('piece_id', $pieceId);

            if ($branchId) {
                $pivotQuery->where('branch_id', $branchId);
            }

            $pivotData = $pivotQuery->first();

            if ($pivotData && $pivotData->price !== null) {
                $serviceData['custom_price'] = (float) $pivotData->price;
                $serviceData['has_custom_price'] = true;
                $serviceData['price'] = (float) $pivotData->price;
                $serviceData['piece_id'] = $pivotData->piece_id;

                if ($branchId) {
                    $serviceData['branch_id'] = (int) $pivotData->branch_id;
                }
            }
        } elseif ($branchId && ! isset($serviceData['has_custom_price'])) {
            $serviceData['has_custom_price'] = false;
        }

        return $serviceData;
    }

    private function respondWithAdditionalServicesAvailableForBranch(Request $request, int $branchId): JsonResponse
    {
        $employee = $request->user();
        $vendorId = $employee->vendor_id;
        $lang = app()->getLocale();

        $branch = Branch::where('id', $branchId)->where('vendor_id', $vendorId)->first();

        if (! $branch) {
            return notFoundResponse(__('branch.branch_not_found_or_not_yours'));
        }

        $additions = ServiceAddition::query()
            ->where('vendor_id', $vendorId)
            ->whereDoesntHave('branches', function ($q) use ($branchId) {
                $q->where('branches.id', $branchId);
            })
            ->whereDoesntHave('pieces', function ($q) use ($branchId) {
                $q->where('service_addition_piece.branch_id', $branchId);
            })
            ->with('iconRelation')
            ->orderBy('id')
            ->get()
            ->map(fn (ServiceAddition $addition) => $this->formatAdditionalServiceDropdownOption($addition, $lang));

        return successResponse([
            'branch_id' => $branchId,
            'branch_name' => $branch->getTranslation('name', $lang),
            'additional_services' => $additions,
        ], __('service.additional_services_available_for_branch_retrieved'));
    }

    /**
     * @return array{
     *     id: int,
     *     service_addition_id: int,
     *     name: array{ar: string, en: string},
     *     display_name: string,
     *     price: float,
     *     icon_id: ?int,
     *     icon: ?string
     * }
     */
    private function formatAdditionalServiceDropdownOption(ServiceAddition $addition, string $lang): array
    {
        $name = [
            'ar' => $addition->getTranslation('name', 'ar', false) ?: '',
            'en' => $addition->getTranslation('name', 'en', false) ?: '',
        ];

        return [
            'id' => $addition->id,
            'service_addition_id' => $addition->id,
            'name' => $name,
            'display_name' => $name[$lang] ?? $name['ar'] ?? $name['en'] ?? '',
            'price' => (float) ($addition->price ?? 0),
            'icon_id' => $addition->icon_id,
            'icon' => $addition->iconRelation
                ? $this->uploadFilesService->getFullUrl($addition->iconRelation->path)
                : null,
        ];
    }
}
