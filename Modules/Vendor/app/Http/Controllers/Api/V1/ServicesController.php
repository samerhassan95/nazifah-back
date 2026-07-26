<?php

namespace Modules\Vendor\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\UploadFilesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Modules\Admin\Models\Icon;
use Modules\Branch\Models\Branch;
use Modules\Order\Models\Order;
use Modules\Piece\Models\Piece;
use Modules\Service\Models\Service;
use Modules\Service\Support\ServiceVendorOffering;
use Modules\Vendor\Models\Vendor;

class ServicesController extends Controller
{
    protected $uploadFilesService;

    public function __construct(UploadFilesService $uploadFilesService)
    {
        $this->uploadFilesService = $uploadFilesService;
    }

    /**
     * Services available to add (two modes):
     * - GET /vendor/available-services?vendor_id=26 (or vendot_id) → admin catalog not yet in vendor catalog
     * - GET /vendor/available-services?branch_id=24 → vendor catalog services not yet on that branch
     */
    public function availableServices(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'vendor_id' => ['nullable', 'integer', 'exists:vendors,id'],
            'vendot_id' => ['nullable', 'integer', 'exists:vendors,id'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $employee = $request->user();
        $tokenVendorId = (int) $employee->vendor_id;
        $branchId = $request->input('branch_id');

        if ($branchId) {
            return $this->availableServicesForBranch((int) $branchId, $tokenVendorId);
        }

        $vendorId = (int) ($request->input('vendor_id') ?? $request->input('vendot_id') ?? $tokenVendorId);

        if ($vendorId !== $tokenVendorId) {
            return notFoundResponse(__('branch.branch_not_found_or_not_yours'));
        }

        return successResponse(
            $this->mapSystemCatalogServices($vendorId),
            __('service.available_services_retrieved')
        );
    }

    /**
     * List services (three modes):
     * - GET /vendor/services → all rows in services table (system catalog)
     * - GET /vendor/services?vendor_id=26 (or vendot_id) → vendor catalog (vendor_service)
     * - GET /vendor/services?branch_id=26 → services linked to that branch (branch_service)
     */
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'vendor_id' => ['nullable', 'integer', 'exists:vendors,id'],
            'vendot_id' => ['nullable', 'integer', 'exists:vendors,id'],
            'system_service' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $employee = $request->user();
        $tokenVendorId = (int) $employee->vendor_id;
        $branchId = $request->input('branch_id');
        $requestedVendorId = $request->input('vendor_id') ?? $request->input('vendot_id');

        if ($requestedVendorId !== null && (int) $requestedVendorId !== $tokenVendorId) {
            return notFoundResponse(__('branch.branch_not_found_or_not_yours'));
        }

        // Legacy alias: system_service=true → same as no filters (full DB catalog)
        if ($request->boolean('system_service') && ! $branchId && $requestedVendorId === null) {
            return successResponse(
                $this->mapSystemCatalogServices(),
                __('service.system_services_retrieved')
            );
        }

        if ($branchId) {
            return $this->indexBranchServices($request, (int) $branchId, $tokenVendorId);
        }

        if ($requestedVendorId !== null) {
            return $this->indexVendorCatalogServices((int) $requestedVendorId);
        }

        return successResponse(
            $this->mapSystemCatalogServices(),
            __('service.system_services_retrieved')
        );
    }

    private function availableServicesForBranch(int $branchId, int $vendorId): JsonResponse
    {
        $branch = Branch::where('id', $branchId)->where('vendor_id', $vendorId)->first();
        if (! $branch) {
            return notFoundResponse(__('branch.branch_not_found_or_not_yours'));
        }

        return successResponse(
            $this->mapVendorCatalogServicesAvailableForBranch($branchId, $vendorId),
            __('service.services_available_for_branch_retrieved')
        );
    }

    /**
     * Vendor catalog services not yet linked to this branch (for add-to-branch UI).
     */
    private function mapVendorCatalogServicesAvailableForBranch(int $branchId, int $vendorId)
    {
        $locale = app()->getLocale();

        return Service::whereHas('vendors', function ($q) use ($vendorId) {
            $q->where('vendors.id', $vendorId);
        })
            ->whereDoesntHave('branches', function ($q) use ($branchId) {
                $q->where('branches.id', $branchId);
            })
            ->orderBy('id')
            ->get()
            ->map(function ($service) use ($vendorId, $branchId, $locale) {
                $vendorIconId = ServiceVendorOffering::iconIdForVendor($vendorId, $service);
                $iconPath = null;
                if ($vendorIconId) {
                    $icon = Icon::find($vendorIconId);
                    $iconPath = $icon ? $this->uploadFilesService->getFullUrl($icon->path) : null;
                }
                if (! $iconPath) {
                    $iconPath = $this->uploadFilesService->getFullUrl($service->icon);
                }

                return array_merge([
                    'id' => $service->id,
                    'service_name' => ServiceVendorOffering::displayNameForVendor($service, $vendorId, $locale),
                    'description' => ServiceVendorOffering::descriptionForVendor($service, $vendorId, $locale),
                    'icon' => $iconPath,
                    'rating' => 0,
                    'branch_id' => $branchId,
                    'source' => 'available_for_branch',
                ], \App\Support\CatalogActivePresenter::service($service));
            });
    }

    /**
     * @param  int|null  $excludeVendorId  When set, omit services already in that vendor's catalog
     */
    private function mapSystemCatalogServices(?int $excludeVendorId = null)
    {
        $locale = app()->getLocale();

        $query = Service::with('category')
            ->orderBy('order')
            ->orderBy('id');

        if ($excludeVendorId !== null) {
            $query->whereDoesntHave('vendors', function ($q) use ($excludeVendorId) {
                $q->where('vendors.id', $excludeVendorId);
            });
        }

        return $query->get()
            ->map(function ($service) use ($locale, $excludeVendorId) {
                $description = null;
                if ($service->description) {
                    $description = is_array($service->description)
                        ? $service->description
                        : json_decode($service->description, true);
                }

                return array_merge([
                    'id' => $service->id,
                    'service_name' => $service->getTranslation('service_name', $locale),
                    'description' => $description,
                    'icon' => $this->uploadFilesService->getFullUrl($service->icon),
                    'rating' => 0,
                    'vendor_id' => $excludeVendorId,
                    'source' => $excludeVendorId !== null ? 'available_for_vendor' : 'system_catalog',
                ], \App\Support\CatalogActivePresenter::service($service));
            });
    }

    private function indexVendorCatalogServices(int $vendorId): JsonResponse
    {
        $locale = app()->getLocale();
        $branchIdsForRating = Branch::where('vendor_id', $vendorId)->pluck('id')->toArray();

        $services = Service::whereHas('vendors', function ($q) use ($vendorId) {
            $q->where('vendors.id', $vendorId);
        })
            ->with(['vendors' => function ($q) use ($vendorId) {
                $q->where('vendors.id', $vendorId)
                    ->withPivot('icon_id', 'description', 'name');
            }])
            ->orderBy('id')
            ->get()
            ->map(function ($service) use ($vendorId, $branchIdsForRating, $locale) {
                $rating = empty($branchIdsForRating) ? 0 : (Order::whereHas('items.service', function ($query) use ($service) {
                    $query->where('services.id', $service->id);
                })
                    ->whereIn('branch_id', $branchIdsForRating)
                    ->whereNotNull('rating')
                    ->avg('rating') ?? 0);

                $vendorIconId = ServiceVendorOffering::iconIdForVendor($vendorId, $service);
                $iconPath = null;
                if ($vendorIconId) {
                    $icon = Icon::find($vendorIconId);
                    $iconPath = $icon ? $this->uploadFilesService->getFullUrl($icon->path) : null;
                }
                if (! $iconPath) {
                    $iconPath = $this->uploadFilesService->getFullUrl($service->icon);
                }

                return array_merge([
                    'id' => $service->id,
                    'service_name' => ServiceVendorOffering::displayNameForVendor($service, $vendorId, $locale),
                    'description' => ServiceVendorOffering::descriptionForVendor($service, $vendorId, $locale),
                    'icon' => $iconPath,
                    'rating' => round((float) $rating, 2),
                    'vendor_id' => $vendorId,
                    'source' => 'vendor_catalog',
                ], \App\Support\CatalogActivePresenter::service($service));
            });

        return successResponse($services, __('service.services_retrieved'));
    }

    private function indexBranchServices(Request $request, int $branchId, int $vendorId): JsonResponse
    {
        $branch = Branch::where('id', $branchId)->where('vendor_id', $vendorId)->first();
        if (! $branch) {
            return notFoundResponse(__('branch.branch_not_found_or_not_yours'));
        }

        $locale = app()->getLocale();

        $services = Service::whereHas('branches', function ($q) use ($branchId) {
            $q->where('branches.id', $branchId);
        })
            ->with(['branches' => function ($q) use ($branchId) {
                $q->where('branches.id', $branchId)
                    ->withPivot('icon_id', 'description', 'name');
            }])
            ->orderBy('id')
            ->get()
            ->map(function ($service) use ($branchId, $locale) {
                $rating = Order::whereHas('items.service', function ($query) use ($service) {
                    $query->where('services.id', $service->id);
                })
                    ->where('branch_id', $branchId)
                    ->whereNotNull('rating')
                    ->avg('rating') ?? 0;

                $pivot = $service->branches->first()?->pivot;
                $serviceName = $service->getTranslation('service_name', $locale);

                if ($pivot && $pivot->name) {
                    if (is_string($pivot->name)) {
                        $decoded = json_decode($pivot->name, true);
                        if ($decoded && isset($decoded[$locale])) {
                            $serviceName = $decoded[$locale];
                        }
                    } elseif (is_array($pivot->name) && isset($pivot->name[$locale])) {
                        $serviceName = $pivot->name[$locale];
                    }
                }

                $description = null;
                if ($pivot && $pivot->description) {
                    if (is_string($pivot->description)) {
                        $decoded = json_decode($pivot->description, true);
                        if ($decoded && isset($decoded[$locale])) {
                            $description = $decoded[$locale];
                        }
                    } elseif (is_array($pivot->description) && isset($pivot->description[$locale])) {
                        $description = $pivot->description[$locale];
                    }
                } elseif ($service->description) {
                    $description = $service->getTranslation('description', $locale, false) ?: null;
                }

                $iconPath = null;
                if ($pivot && $pivot->icon_id) {
                    $icon = Icon::find($pivot->icon_id);
                    $iconPath = $icon ? $this->uploadFilesService->getFullUrl($icon->path) : null;
                }
                if (! $iconPath) {
                    $iconPath = $this->uploadFilesService->getFullUrl($service->icon);
                }

                return array_merge([
                    'id' => $service->id,
                    'service_name' => $serviceName,
                    'description' => $description,
                    'icon' => $iconPath,
                    'rating' => round((float) $rating, 2),
                    'branch_id' => $branchId,
                    'source' => 'branch',
                ], \App\Support\CatalogActivePresenter::service($service, $branchId, $pivot));
            });

        return successResponse($services, __('service.services_retrieved'));
    }

    /**
     * Add a system service to the vendor catalog (does not assign to branches).
     * POST /api/v1/vendor/services
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'service_id' => ['required', 'integer', 'exists:services,id'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $employee = $request->user();
        $vendorId = (int) $employee->vendor_id;

        $service = Service::find($request->service_id);
        if (! $service) {
            return notFoundResponse(__('service.service_not_found'));
        }

        $alreadyInCatalog = Vendor::find($vendorId)
            ?->catalogServices()
            ->where('services.id', $service->id)
            ->exists();

        ServiceVendorOffering::upsert($vendorId, $service, []);
        flushCacheTags(['services', 'vendors']);

        $locale = app()->getLocale();
        $vendorIconId = ServiceVendorOffering::iconIdForVendor($vendorId, $service);
        $iconPath = null;
        if ($vendorIconId) {
            $icon = Icon::find($vendorIconId);
            $iconPath = $icon ? $this->uploadFilesService->getFullUrl($icon->path) : null;
        }
        if (! $iconPath) {
            $iconPath = $this->uploadFilesService->getFullUrl($service->icon);
        }

        $statusCode = $alreadyInCatalog ? 200 : 201;
        $message = $alreadyInCatalog
            ? __('service.service_already_in_vendor_catalog')
            : __('service.service_added_to_vendor_catalog');

        return successResponse([
            'id' => $service->id,
            'service_name' => ServiceVendorOffering::displayNameForVendor($service, $vendorId, $locale),
            'description' => ServiceVendorOffering::descriptionForVendor($service, $vendorId, $locale),
            'icon' => $iconPath,
            'rating' => 0,
        ], $message, $statusCode);
    }

    /**
     * Remove service from this vendor only (vendor_service + branch links).
     * Does NOT delete rows from the services table — system catalog is unchanged.
     * DELETE /api/v1/vendor/services/{serviceId}
     */
    public function destroy(Request $request, $serviceId): JsonResponse
    {
        $employee = $request->user();
        $vendorId = (int) $employee->vendor_id;
        $serviceId = (int) $serviceId;

        if (! Service::where('id', $serviceId)->exists()) {
            return notFoundResponse(__('service.service_not_found'));
        }

        if (! ServiceVendorOffering::find($vendorId, $serviceId)) {
            return notFoundResponse(__('service.service_not_in_vendor_catalog'));
        }

        ServiceVendorOffering::removeFromVendor($vendorId, $serviceId);
        flushCacheTags(['services', 'vendors', 'branches', 'pieces']);

        return successResponse([
            'vendor_id' => $vendorId,
            'service_id' => $serviceId,
            'removed_from_vendor' => true,
            'system_service_deleted' => false,
        ], __('service.service_removed_from_vendor_catalog'));
    }

    /**
     * Get service details
     */
    public function show(Request $request, $serviceId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $employee = $request->user();
        $vendorId = $employee->vendor_id;
        $branchId = $request->input('branch_id');

        $service = Service::with(['category'])->find($serviceId);

        if (! $service) {
            return notFoundResponse(__('service.service_not_found'));
        }

        // If branch_id is provided, return branch-specific data
        if ($branchId) {
            // Verify branch belongs to vendor
            $branch = Branch::where('id', $branchId)->where('vendor_id', $vendorId)->first();
            if (! $branch) {
                return notFoundResponse(__('branch.branch_not_found_or_not_yours'));
            }

            // Get service with branch pivot data
            $serviceWithBranch = Service::where('id', $serviceId)
                ->whereHas('branches', function ($q) use ($branchId) {
                    $q->where('branches.id', $branchId);
                })
                ->with(['branches' => function ($q) use ($branchId) {
                    $q->where('branches.id', $branchId)
                        ->withPivot('icon_id', 'description', 'name');
                }])
                ->first();

            if (! $serviceWithBranch || $serviceWithBranch->branches->isEmpty()) {
                return notFoundResponse(__('service.service_not_found_for_branch'));
            }

            $pivot = $serviceWithBranch->branches->first()->pivot;

            // Get service name from pivot or fallback to service name
            $serviceNameAr = $service->getTranslation('service_name', 'ar');
            $serviceNameEn = $service->getTranslation('service_name', 'en');
            if ($pivot->name) {
                $pivotName = is_string($pivot->name) ? json_decode($pivot->name, true) : $pivot->name;
                if (is_array($pivotName)) {
                    $serviceNameAr = $pivotName['ar'] ?? $serviceNameAr;
                    $serviceNameEn = $pivotName['en'] ?? $serviceNameEn;
                }
            }

            // Get description from pivot or fallback to service description
            $descriptionAr = $service->getTranslation('description', 'ar');
            $descriptionEn = $service->getTranslation('description', 'en');
            if ($pivot->description) {
                $pivotDesc = is_string($pivot->description) ? json_decode($pivot->description, true) : $pivot->description;
                if (is_array($pivotDesc)) {
                    $descriptionAr = $pivotDesc['ar'] ?? $descriptionAr;
                    $descriptionEn = $pivotDesc['en'] ?? $descriptionEn;
                }
            }

            // Get icon from pivot or fallback to service icon
            $iconPath = null;
            if ($pivot->icon_id) {
                $icon = Icon::find($pivot->icon_id);
                $iconPath = $icon ? $this->uploadFilesService->getFullUrl($icon->path) : null;
            }
            if (! $iconPath) {
                $iconPath = $this->uploadFilesService->getFullUrl($service->icon);
            }

            // Calculate rating only for this branch
            $rating = Order::whereHas('items.service', function ($query) use ($service) {
                $query->where('services.id', $service->id);
            })
                ->where('branch_id', $branchId)
                ->whereNotNull('rating')
                ->avg('rating') ?? 0;

            // Get reviews only for this branch
            $reviews = Order::whereHas('items.service', function ($query) use ($service) {
                $query->where('services.id', $service->id);
            })
                ->where('branch_id', $branchId)
                ->whereNotNull('rating')
                ->with('client')
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get()
                ->map(function ($order) {
                    $clientName = 'Anonymous';
                    if ($order->client) {
                        $clientName = $order->client->full_name ?? 'Anonymous';
                    }

                    return [
                        'User_name' => $clientName,
                        'User_image' => $order->client ? $this->uploadFilesService->getFullUrl($order->client->image) : null,
                        'rating' => $order->rating,
                        'comment' => $order->review,
                        'date' => $order->created_at->format('Y-m-d'),
                    ];
                });

            // Get pieces for this branch
            $pieces = Piece::whereHas('services', function ($q) use ($serviceId) {
                $q->where('services.id', $serviceId);
            })
                ->whereHas('branches', function ($q) use ($branchId) {
                    $q->where('branches.id', $branchId);
                    if (Schema::hasColumn('branch_piece', 'is_active')) {
                        $q->where('branch_piece.is_active', true);
                    }
                })
                ->with(['iconRelation'])
                ->get();

            $pivotMap = \Modules\Piece\Support\PieceBranchOffering::pivotMapForPieces(
                $branchId,
                $pieces->pluck('id')->map(fn ($id) => (int) $id)->all()
            );

            $pieces = $pieces->map(function ($piece) use ($service, $branchId, $pivotMap) {
                return \Modules\Piece\Support\PiecePricingFormatter::pieceUnderService(
                    $piece,
                    $service,
                    $branchId,
                    app()->getLocale(),
                    $pivotMap[(int) $piece->id] ?? null
                );
            });

            // Return same structure but with branch-specific data
            return successResponse([
                'service_id' => $service->id,
                'service_name' => [
                    'ar' => $serviceNameAr,
                    'en' => $serviceNameEn,
                ],
                'description' => [
                    'ar' => $descriptionAr,
                    'en' => $descriptionEn,
                ],
                'rating' => round((float) $rating, 2),
                'icon' => $iconPath,
                'branches' => [[
                    'id' => $branch->id,
                    'name' => $branch->getTranslation('name', app()->getLocale()),
                    'location' => $branch->getTranslation('location', app()->getLocale()) ?: $branch->national_address,
                    'latitude' => (float) $branch->latitude,
                    'longitude' => (float) $branch->longitude,
                    'branch_specific_description' => is_array($pivotDesc ?? null) ? $pivotDesc : null,
                ]],
                'pieces' => $pieces,
                'reviews' => $reviews,
            ], __('service.service_details_retrieved'));
        }

        // Original logic: return service with all vendor branches
        $rating = Order::whereHas('items.service', function ($query) use ($service) {
            $query->where('services.id', $service->id);
        })
            ->whereNotNull('rating')
            ->avg('rating') ?? 0;

        // Get reviews
        $reviews = Order::whereHas('items.service', function ($query) use ($service) {
            $query->where('services.id', $service->id);
        })
            ->whereNotNull('rating')
            ->with('client')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($order) {
                $clientName = 'Anonymous';
                if ($order->client) {
                    $clientName = $order->client->full_name ?? 'Anonymous';
                }

                return [
                    'User_name' => $clientName,
                    'User_image' => $order->client ? $this->uploadFilesService->getFullUrl($order->client->image) : null,
                    'rating' => $order->rating,
                    'comment' => $order->review,
                    'date' => $order->created_at->format('Y-m-d'),
                ];
            });

        // Get only branches that belong to this vendor AND offer this service
        $vendorBranchesForService = $service->branches()
            ->where('branches.vendor_id', $vendorId)
            ->withPivot('icon_id', 'description')
            ->get();

        $branchIds = $vendorBranchesForService->pluck('id')->toArray();

        $branches = $vendorBranchesForService->map(function ($branch) {
            // Get branch-specific description
            $branchDescription = null;
            if ($branch->pivot && $branch->pivot->description) {
                if (is_string($branch->pivot->description)) {
                    $decoded = json_decode($branch->pivot->description, true);
                    $branchDescription = $decoded ?: null;
                } else {
                    $branchDescription = $branch->pivot->description;
                }
            }

            return [
                'id' => $branch->id,
                'name' => $branch->getTranslation('name', app()->getLocale()),
                'location' => $branch->getTranslation('location', app()->getLocale()) ?: $branch->national_address,
                'latitude' => (float) $branch->latitude,
                'longitude' => (float) $branch->longitude,
                'branch_specific_description' => $branchDescription,
            ];
        });

        // Build top-level description: use service description, fallback to first branch's pivot description
        $descriptionAr = $service->getTranslation('description', 'ar');
        $descriptionEn = $service->getTranslation('description', 'en');
        if ((empty($descriptionAr) && empty($descriptionEn)) && $vendorBranchesForService->isNotEmpty()) {
            $firstBranch = $vendorBranchesForService->first();
            if ($firstBranch->pivot && $firstBranch->pivot->description) {
                $pivotDesc = $firstBranch->pivot->description;
                if (is_string($pivotDesc)) {
                    $decoded = json_decode($pivotDesc, true);
                    if ($decoded) {
                        $descriptionAr = $decoded['ar'] ?? null;
                        $descriptionEn = $decoded['en'] ?? null;
                    }
                } elseif (is_array($pivotDesc)) {
                    $descriptionAr = $pivotDesc['ar'] ?? null;
                    $descriptionEn = $pivotDesc['en'] ?? null;
                }
            }
        }

        // Get only pieces that are linked to this service AND assigned to at least one of these vendor branches (active)
        $piecesQuery = Piece::query()
            ->whereHas('services', function ($q) use ($serviceId) {
                $q->where('services.id', $serviceId);
            });

        if (! empty($branchIds)) {
            $piecesQuery->whereHas('branches', function ($q) use ($branchIds) {
                $q->whereIn('branches.id', $branchIds);
                if (Schema::hasColumn('branch_piece', 'is_active')) {
                    $q->where('branch_piece.is_active', true);
                }
            });
        }

        $defaultBranchId = ! empty($branchIds) ? (int) $branchIds[0] : null;

        $piecesCollection = $piecesQuery->with(['iconRelation'])->get();
        $pivotMap = $defaultBranchId !== null
            ? \Modules\Piece\Support\PieceBranchOffering::pivotMapForPieces(
                $defaultBranchId,
                $piecesCollection->pluck('id')->map(fn ($id) => (int) $id)->all()
            )
            : [];

        $pieces = $piecesCollection->map(function ($piece) use ($service, $defaultBranchId, $pivotMap) {
            if ($defaultBranchId !== null) {
                return \Modules\Piece\Support\PiecePricingFormatter::pieceUnderService(
                    $piece,
                    $service,
                    $defaultBranchId,
                    app()->getLocale(),
                    $pivotMap[(int) $piece->id] ?? null
                );
            }

            return [
                'id' => $piece->id,
                'name' => $piece->getTranslation('name', app()->getLocale()),
                'service_id' => $service->id,
                'price' => (float) ($piece->getPriceForService($service->id) ?? 0),
                'icon' => $piece->iconRelation?->full_path,
            ];
        });

        return successResponse([
            'service_id' => $service->id,
            'service_name' => [
                'ar' => $service->getTranslation('service_name', 'ar'),
                'en' => $service->getTranslation('service_name', 'en'),
            ],
            'description' => [
                'ar' => $descriptionAr,
                'en' => $descriptionEn,
            ],
            'rating' => round((float) $rating, 2),
            'icon' => $service->icon,
            'branches' => $branches,
            'pieces' => $pieces,
            'reviews' => $reviews,
        ], __('service.service_details_retrieved'));
    }

    /**
     * Filter services
     */
    public function filter(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['nullable', 'string'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $branchId = $request->input('branch_id');
        $query = Service::query();

        // If branch_id provided, filter by branch relationship
        if ($branchId) {
            $query->whereHas('branches', function ($q) use ($branchId) {
                $q->where('branches.id', $branchId);
            });
        }

        // Filter by name if provided
        if ($request->has('name') && $request->name) {
            $searchName = $request->name;
            $query->where(function ($q) use ($searchName) {
                $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(service_name, '$.ar')) LIKE ?", ["%{$searchName}%"])
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(service_name, '$.en')) LIKE ?", ["%{$searchName}%"]);
            });
        }

        $services = $query->get()
            ->map(function ($service) {
                $rating = Order::whereHas('items.service', function ($query) use ($service) {
                    $query->where('services.id', $service->id);
                })
                    ->whereNotNull('rating')
                    ->avg('rating') ?? 0;

                $description = null;
                if ($service->description) {
                    $description = is_array($service->description)
                        ? $service->description
                        : json_decode($service->description, true);
                }

                return [
                    'service_id' => $service->id,
                    'name' => $service->getTranslation('service_name', app()->getLocale()),
                    'description' => $description,
                    'rating' => round((float) $rating, 2),
                    'icon' => $this->uploadFilesService->getFullUrl($service->icon),
                ];
            });

        return successResponse($services, __('service.filtered_services_retrieved'));
    }
}
