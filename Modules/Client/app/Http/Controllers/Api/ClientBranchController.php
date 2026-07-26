<?php

namespace Modules\Client\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Services\UploadFilesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Modules\Branch\Models\Branch;
use Modules\Order\Models\Order;
use Modules\Piece\Models\Piece;
use Modules\Service\Models\Service;
use Modules\Zone\Models\Zone;

class ClientBranchController extends Controller
{
    protected $uploadFilesService;

    public function __construct(UploadFilesService $uploadFilesService)
    {
        $this->uploadFilesService = $uploadFilesService;
    }

    /**
     * Get all branches in a zone with vendor data
     * GET /api/v1/user/branches?zone_id=1
     */
    public function index(Request $request): JsonResponse
    {
        $locale = app()->getLocale();
        $vendorCondition = function ($q) {
            $q->where('is_active', true)
                ->where(function ($q2) {
                    $q2->where('is_banned', false)->orWhereNull('is_banned');
                });
        };

        $filters = resolveClientBranchFiltersFromRequest($request);
        $zoneId = $filters['zone_id'];
        $address = $filters['address'];
        $searchLat = $filters['latitude'];
        $searchLng = $filters['longitude'];

        $zone = null;
        if ($zoneId) {
            $zone = Zone::where('id', $zoneId)->where('is_active', true)->first();
        }

        // Branch IDs filtered by zone (including polygon fallback)
        $allIds = collect();
        if ($zone) {
            $idsInZone = Branch::where('zone_id', $zone->id)
                ->where('is_active', true)
                ->whereHas('vendor', $vendorCondition)
                ->pluck('id');

            $idsInPolygon = collect();
            if ($zone->points) {
                $candidates = Branch::whereNull('zone_id')
                    ->whereNotNull('latitude')
                    ->whereNotNull('longitude')
                    ->where('is_active', true)
                    ->whereHas('vendor', $vendorCondition)
                    ->get();
                $idsInPolygon = $candidates->filter(function ($branch) use ($zone) {
                    return $zone->isPointInZone((float) $branch->latitude, (float) $branch->longitude);
                })->pluck('id');
            }

            $allIds = $idsInZone->merge($idsInPolygon)->unique()->values();
        }

        $query = Branch::query()
            ->where('is_active', true)
            ->whereHas('vendor', $vendorCondition)
            ->with(['vendor', 'zone', 'workingHourShifts']);

        if ($zone) {
            // Strictly enforce the zone filtering. If no branches in zone, it will correctly return []
            $query->whereIn('id', $allIds);
        } else {
            // No valid zone provided/found => fetch all active branches.
        }

        if ($searchLat !== null && $searchLng !== null) {
            $query->selectRaw('branches.*,
                (6371 * acos(cos(radians(?)) * cos(radians(branches.latitude)) *
                cos(radians(branches.longitude) - radians(?)) + sin(radians(?)) *
                sin(radians(branches.latitude)))) AS distance', [$searchLat, $searchLng, $searchLat])
                ->orderBy('distance');
        } else {
            $query->orderBy('rating', 'desc');
        }

        $isCacheable = $searchLat === null && $searchLng === null;

        if ($isCacheable) {
            $tags = ['branches', 'vendors', 'zones'];
            $versionKey = getTagVersionKey($tags);
            $cacheKey = "client:v1:branches:z{$zoneId}:v{$versionKey}:{$locale}:p".($request->per_page ?? 15).':page'.($request->page ?? 1);

            $result = Cache::remember($cacheKey, 1800, function () use ($query, $request, $locale, $zone, $address) {
                return $this->getBranchesData($query, $request, $locale, $zone, $address);
            });
            $branches = $result['branches'];
            $meta = $result['meta'];
        } else {
            $result = $this->getBranchesData($query, $request, $locale, $zone, $address);
            $branches = $result['branches'];
            $meta = $result['meta'];
        }

        return jsonResponse(true, 200, __('branch.branches_retrieved'), $branches, $meta);
    }

    private function getBranchesData($query, $request, $locale, $zone, $address)
    {
        $branches = $query->paginate($request->per_page ?? 15);

        $branches->getCollection()->transform(function ($branch) use ($locale) {
            $vendor = $branch->vendor;

            return [
                'id' => $branch->id,
                'name' => $branch->getTranslation('name', $locale),
                'phone' => $branch->phone_number,
                'land_phone' => $branch->land_phone,
                'address' => $branch->getTranslation('location', $locale),
                'national_address' => $branch->national_address,
                'description' => $branch->getTranslation('description', $locale),
                'latitude' => $branch->latitude ? (float) $branch->latitude : null,
                'longitude' => $branch->longitude ? (float) $branch->longitude : null,
                'image_cover' => $this->uploadFilesService->getFullUrl($branch->store_front),
                'image_logo' => $this->uploadFilesService->getFullUrl($branch->logo),
                'working_hours' => $branch->getApiWorkingHours(),
                'rating' => (float) ($branch->rating ?? 0),
                'rate_count' => $branch->rate_count ?? 0,
                'distance' => isset($branch->distance) ? round($branch->distance, 2).' km' : null,
                'home_pickup' => (bool) $branch->home_pickup,
                'self_dropoff' => (bool) $branch->self_dropoff,
                'home_delivery' => (bool) $branch->home_delivery,
                'self_pickup' => (bool) $branch->self_pickup,
                'delivery_price_per_km' => (float) ($vendor->delivery_price_per_km ?? 0),
                'vendor' => [
                    'id' => $vendor->id,
                    'name' => $vendor->getTranslation('name', $locale),
                    'logo' => $this->uploadFilesService->getFullUrl($vendor->logo),
                    'delivery_price_per_km' => (float) ($vendor->delivery_price_per_km ?? 0),
                ],
                'zone' => [
                    'id' => $branch->zone_id,
                    'name' => $branch->zone ? $branch->zone->name : null,
                    'zone_color' => $branch->zone ? $branch->zone->zone_color : null,
                ],
            ];
        });

        $meta = [
            'current_page' => $branches->currentPage(),
            'from' => $branches->firstItem(),
            'last_page' => $branches->lastPage(),
            'per_page' => $branches->perPage(),
            'to' => $branches->lastItem(),
            'total' => $branches->total(),
            'zone_id' => $zone?->id,
            'address_id' => $address?->id,
        ];

        return ['branches' => $branches->items(), 'meta' => $meta];
    }

    /**
     * Get branch details with full information including services
     * GET /api/v1/user/branches/{branch_id}
     */
    public function show(Request $request, string $branchId): JsonResponse
    {
        $locale = app()->getLocale();
        $tags = ["branch_{$branchId}", 'branches', 'vendors'];
        $versionKey = getTagVersionKey($tags);
        $cacheKey = "client:v1:branch:{$branchId}:details:v{$versionKey}:{$locale}";

        $branchData = Cache::remember($cacheKey, 3600, function () use ($branchId, $locale) {
            $vendorCondition = function ($q) {
                $q->where('is_active', true)
                    ->where(function ($q2) {
                        $q2->where('is_banned', false)->orWhereNull('is_banned');
                    });
            };

            $branch = Branch::with(['vendor', 'zone', 'workingHourShifts'])
                ->where('is_active', true)
                ->whereHas('vendor', $vendorCondition)
                ->find((int) $branchId);

            if (! $branch) {
                return null;
            }

            $vendor = $branch->vendor;

            // Get all active services for this branch
            $services = $branch->activeServices()
                ->orderBy('services.order', 'asc')
                ->get()
                ->map(function ($service) use ($locale, $branch) {
                    return array_merge([
                        'id' => $service->id,
                        'name' => $service->getTranslation('service_name', $locale),
                        'icon' => $this->uploadFilesService->getFullUrl($service->iconRelation?->full_path ?? $service->iconRelation?->path),
                    ], \App\Support\CatalogActivePresenter::service($service, (int) $branch->id, $service->pivot));
                });

            // Get pieces count
            $piecesCount = $branch->activePieces()
                ->count();

            return [
                'id' => $branch->id,
                'name' => $branch->getTranslation('name', $locale),
                'description' => $branch->getTranslation('description', $locale),
                'phone' => $branch->phone_number,
                'land_phone' => $branch->land_phone,
                'address' => $branch->getTranslation('location', $locale),
                'national_address' => $branch->national_address,
                'latitude' => $branch->latitude ? (float) $branch->latitude : null,
                'longitude' => $branch->longitude ? (float) $branch->longitude : null,
                'image_cover' => $this->uploadFilesService->getFullUrl($branch->store_front),
                'image_logo' => $this->uploadFilesService->getFullUrl($branch->logo),
                'working_hours' => $branch->getApiWorkingHours(),
                'rating' => (float) ($branch->rating ?? 0),
                'rate_count' => $branch->rate_count ?? 0,
                'home_pickup' => (bool) $branch->home_pickup,
                'self_dropoff' => (bool) $branch->self_dropoff,
                'home_delivery' => (bool) $branch->home_delivery,
                'self_pickup' => (bool) $branch->self_pickup,
                'delivery_and_pickup' => (bool) $branch->delivery_and_pickup,
                'services_count' => $services->count(),
                'pieces_count' => $piecesCount,
                'delivery_price_per_km' => (float) ($vendor->delivery_price_per_km ?? 0),
                'vendor' => [
                    'id' => $vendor->id,
                    'name' => $vendor->getTranslation('name', $locale),
                    'logo' => $this->uploadFilesService->getFullUrl($vendor->logo),
                    'cover_image' => $this->uploadFilesService->getFullUrl($vendor->cover_image),
                    'phone' => $vendor->phone,
                    'delivery_price_per_km' => (float) ($vendor->delivery_price_per_km ?? 0),
                ],
                'zone' => [
                    'id' => $branch->zone_id,
                    'name' => $branch->zone ? $branch->zone->name : null,
                    'zone_color' => $branch->zone ? $branch->zone->zone_color : null,
                ],
                'services' => $services,
            ];
        });

        if (! $branchData) {
            return notFoundResponse(__('branch.not_found'));
        }

        // Live (uncached) count of orders successfully delivered to this branch
        // (delivered or completed), so it stays accurate even though branch
        // details are cached for an hour.
        $branchData['successful_order_count'] = Order::where('branch_id', (int) $branchId)
            ->whereIn('status', [
                OrderStatus::DELIVERED->value,
                OrderStatus::COMPLETED->value,
            ])
            ->count();

        return successResponse($branchData, __('branch.details_retrieved'));
    }

    /**
     * Get all services for a branch
     * GET /api/v1/user/branches/{branch_id}/services
     */
    public function getServices(Request $request, string $branchId): JsonResponse
    {
        $locale = app()->getLocale();

        $branch = Branch::find((int) $branchId);

        if (! $branch) {
            return notFoundResponse(__('branch.not_found'));
        }

        // Get all active services for this branch
        $services = $branch->activeServices()
            ->orderBy('services.order', 'asc')
            ->get()
            ->map(function ($service) use ($locale, $branch) {
                return array_merge([
                    'id' => $service->id,
                    'name' => $service->getTranslation('service_name', $locale),
                    'icon' => $this->uploadFilesService->getFullUrl($service->iconRelation?->full_path ?? $service->iconRelation?->path),
                    'price' => (float) ($service->getPriceAtBranchOrNull($branch->id) ?? 0),
                    'branch_price' => (float) ($service->pivot->price ?? $service->price ?? 0),
                ], \App\Support\CatalogActivePresenter::service($service, (int) $branch->id, $service->pivot));
            });

        return successResponse([
            'branch_id' => (int) $branchId,
            'services' => $services,
        ], __('branch.services_retrieved'));
    }

    /**
     * Get all pieces for a service in a branch
     * If the service has children, includes pieces from all child services
     * GET /api/v1/user/branches/{branch_id}/services/{service_id}/pieces
     */
    public function getServicePieces(Request $request, string $branchId, string $serviceId): JsonResponse
    {
        $locale = app()->getLocale();
        $branchId = (int) $branchId;
        $serviceId = (int) $serviceId;

        $branch = Branch::find($branchId);

        if (! $branch) {
            return notFoundResponse(__('branch.not_found'));
        }

        $service = Service::find($serviceId);

        if (! $service) {
            return notFoundResponse(__('branch.service_not_found'));
        }

        // Verify branch has this service
        $branchHasService = $branch->activeServices()
            ->where('services.id', $serviceId)
            ->exists();

        if (! $branchHasService) {
            return errorResponse(__('branch.service_not_available'), null, 400);
        }

        // Get pieces for this service that are available at this branch
        $serviceIds = [$serviceId];

        // Get pieces for this service and all its children that are available at this branch
        $piecesCollection = \App\Support\CatalogActiveFilter::scopeActivePieces(
            Piece::whereHas('services', function ($q) use ($serviceIds, $branchId) {
                $q->whereIn('services.id', $serviceIds)
                    ->where('services.is_active', true)
                    ->where('service_piece.branch_id', $branchId);
            })
        )
            ->whereHas('branches', function ($q) use ($branchId) {
                $q->where('branches.id', $branchId)
                    ->where('branch_piece.is_active', true);
            })
            ->with(['iconRelation'])
            ->get()
            ->unique('id');

        $pivotMap = \Modules\Piece\Support\PieceBranchOffering::pivotMapForPieces(
            $branchId,
            $piecesCollection->pluck('id')->map(fn ($id) => (int) $id)->all()
        );

        $pieces = $piecesCollection
            ->map(function ($piece) use ($locale, $service, $branchId, $pivotMap) {
                $item = \Modules\Piece\Support\PiecePricingFormatter::pieceUnderService(
                    $piece,
                    $service,
                    $branchId,
                    $locale,
                    $pivotMap[(int) $piece->id] ?? null,
                    true
                );
                $item['icon'] = $this->uploadFilesService->getFullUrl(
                    $piece->iconRelation?->full_path ?? $piece->iconRelation?->path
                );

                $additionalServices = collect(
                    \Modules\Piece\Support\PiecePricingFormatter::additionalServicesForPiece($piece, $branchId, $locale, true)
                )
                    ->filter(fn (array $addition) => \App\Support\CatalogActivePresenter::isEffectivelyActive($addition))
                    ->values();

                $item['additional_services'] = $additionalServices;
                $item['additional_services_count'] = $additionalServices->count();

                return $item;
            })
            ->values();

        return successResponse([
            'branch_id' => $branchId,
            'service_id' => $serviceId,
            'pieces' => $pieces,
        ], __('branch.pieces_retrieved'));
    }

    /**
     * Get all service additions (additional services) for a piece
     * GET /api/v1/user/branches/{branch_id}/pieces/{piece_id}/additions
     */
    public function getPieceAdditions(Request $request, string $branchId, string $pieceId): JsonResponse
    {
        $locale = app()->getLocale();
        $branchId = (int) $branchId;
        $pieceId = (int) $pieceId;

        $branch = Branch::where('is_active', true)->find($branchId);

        if (! $branch) {
            return notFoundResponse(__('branch.not_found'));
        }

        $piece = Piece::where('is_active', true)->find($pieceId);

        if (! $piece) {
            return notFoundResponse(__('branch.piece_not_found'));
        }

        // Verify branch has this piece
        $branchHasPiece = $branch->activePieces()
            ->where('pieces.id', $pieceId)
            ->exists();

        if (! $branchHasPiece) {
            return errorResponse(__('branch.piece_not_available'), null, 400);
        }

        $additions = collect(
            \Modules\Piece\Support\PiecePricingFormatter::additionalServicesForPiece($piece, $branchId, $locale, true)
        )
            ->filter(fn (array $row) => \App\Support\CatalogActivePresenter::isEffectivelyActive($row))
            ->values();

        return successResponse([
            'branch_id' => $branchId,
            'piece_id' => $pieceId,
            'additional_services' => $additions,
        ], __('branch.additions_retrieved'));
    }

    /**
     * Get service details with all pieces for a specific branch
     * GET /api/v1/user/laundries/{branch_id}/services/{service_id}
     */
    public function getServiceDetails(Request $request, string $branchId, string $serviceId): JsonResponse
    {
        $locale = app()->getLocale();
        $branchId = (int) $branchId;
        $serviceId = (int) $serviceId;

        $service = Service::find($serviceId);

        if (! $service) {
            return notFoundResponse(__('branch.service_not_found'));
        }

        $branch = Branch::find($branchId);

        if (! $branch) {
            return notFoundResponse(__('branch.not_found'));
        }

        // Verify branch has this service
        $branchHasService = $branch->activeServices()
            ->where('services.id', $serviceId)
            ->exists();

        if (! $branchHasService) {
            return errorResponse(__('branch.service_not_available'), null, 400);
        }

        // Get all pieces for this service that are available at this branch
        $pieces = \App\Support\CatalogActiveFilter::scopeActiveServices(
            $service->pieces()
        )
            ->whereHas('branches', function ($q) use ($branchId) {
                $q->where('branches.id', $branchId)
                    ->where('branch_piece.is_active', true);
            })
            ->get()
            ->map(function ($piece) use ($locale, $service, $branchId) {
                return \Modules\Piece\Support\PiecePricingFormatter::pieceUnderService(
                    $piece,
                    $service,
                    $branchId,
                    $locale,
                    null,
                    true
                );
            });

        return successResponse([
            'branch_id' => $branchId,
            'service' => array_merge([
                'id' => $service->id,
                'name' => $service->getTranslation('service_name', $locale),
                'icon' => $this->uploadFilesService->getFullUrl($service->iconRelation?->full_path ?? $service->iconRelation?->path),
            ], \App\Support\CatalogActivePresenter::service($service, $branchId)),
            'pieces' => $pieces,
        ], __('branch.service_details_retrieved'));
    }

    /**
     * Get piece details with all service additions for a specific branch
     * GET /api/v1/user/laundries/{branch_id}/pieces/{piece_id}
     */
    public function getPieceDetails(Request $request, string $branchId, string $pieceId): JsonResponse
    {
        $locale = app()->getLocale();
        $branchId = (int) $branchId;
        $pieceId = (int) $pieceId;

        $piece = Piece::find($pieceId);

        if (! $piece) {
            return notFoundResponse(__('branch.piece_not_found'));
        }

        $branch = Branch::find($branchId);

        if (! $branch) {
            return notFoundResponse(__('branch.not_found'));
        }

        // Verify branch has this piece
        $branchHasPiece = $branch->activePieces()
            ->where('pieces.id', $pieceId)
            ->exists();

        if (! $branchHasPiece) {
            return errorResponse(__('branch.piece_not_available'), null, 400);
        }

        // Get additional services (service additions) for this piece at this branch
        $additions = $piece->additionalServicesAtBranch($branchId)
            ->get()
            ->map(function ($addition) use ($locale, $piece, $branchId) {
                return array_merge([
                    'id' => $addition->id,
                    'name' => $addition->getDisplayNameAtBranch($branchId, $locale),
                    'icon' => $this->uploadFilesService->getFullUrl($addition->iconRelation?->full_path ?? $addition->iconRelation?->path),
                    'price' => (float) $addition->getPriceForPieceAtBranch($piece->id, $branchId),
                ], \App\Support\CatalogActivePresenter::serviceAddition($addition, $branchId));
            });

        $pivotRow = \Modules\Piece\Support\PieceBranchOffering::find((int) $branchId, (int) $piece->id);

        return successResponse([
            'branch_id' => $branchId,
            'piece' => array_merge(
                \Modules\Piece\Support\PieceBranchOffering::branchApiFields($piece, (int) $branchId, $locale, $pivotRow),
                [
                    'id' => $piece->id,
                    'icon' => $this->uploadFilesService->getFullUrl($piece->iconRelation?->full_path ?? $piece->iconRelation?->path),
                ]
            ),
            'additional_services' => $additions,
        ], __('branch.piece_details_retrieved'));
    }
}
