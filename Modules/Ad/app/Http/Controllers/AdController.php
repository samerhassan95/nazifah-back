<?php

namespace Modules\Ad\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Ad\Http\Requests\StoreAdRequest;
use Modules\Ad\Http\Requests\UpdateAdRequest;
use Modules\Ad\Http\Resources\AdResource;
use Modules\Ad\Services\AdService;

class AdController extends Controller
{
    public function __construct(private AdService $adService) {}

    public function index(): JsonResponse
    {
        $ads = $this->adService->getAllAds();

        return successResponse(
            AdResource::collection($ads),
            'Ads retrieved successfully'
        );
    }

    public function store(StoreAdRequest $request): JsonResponse
    {
        $ad = $this->adService->createAd($request->validated());

        return successResponse(new AdResource($ad), 'Ad created successfully', 201);
    }

    public function show(int $id): JsonResponse
    {
        $ad = $this->adService->getAdById($id);
        if (! $ad) {
            return notFoundResponse('Ad not found');
        }

        return successResponse(new AdResource($ad), 'Ad retrieved successfully');
    }

    public function update(UpdateAdRequest $request, int $id): JsonResponse
    {
        $ad = $this->adService->updateAd($id, $request->validated());
        if (! $ad) {
            return notFoundResponse('Ad not found');
        }

        return successResponse(new AdResource($ad), 'Ad updated successfully');
    }

    public function destroy(int $id): JsonResponse
    {
        $deleted = $this->adService->deleteAd($id);
        if (! $deleted) {
            return notFoundResponse('Ad not found');
        }

        return successResponse(null, 'Ad deleted successfully');
    }
}
