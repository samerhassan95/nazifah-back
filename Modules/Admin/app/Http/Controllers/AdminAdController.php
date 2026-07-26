<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\UploadFilesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Ad\Services\AdService;
use Modules\Admin\Http\Requests\StoreAdRequest;
use Modules\Admin\Http\Requests\UpdateAdRequest;
use Modules\Admin\Http\Resources\AdResource;

class AdminAdController extends Controller
{
    public function __construct(
        private AdService $adService,
        private UploadFilesService $uploadFilesService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $ads = $this->adService->getAllAds($request->all());

        return successResponse(
            AdResource::collection($ads),
            'Ads retrieved successfully'
        );
    }

    public function store(StoreAdRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $validated['is_active'] = $validated['is_active'] ?? true;

        if ($request->hasFile('image')) {
            $validated['image'] = $this->uploadFilesService->uploadImage($request->file('image'), 'ads');
        }

        $ad = $this->adService->createAd($validated);

        return successResponse($ad, 'Ad created successfully', 201);
    }

    public function show(int $id): JsonResponse
    {
        $ad = $this->adService->getAdById($id);

        if (! $ad) {
            return notFoundResponse('Ad not found');
        }

        return successResponse(
            [
                'id' => $ad->id,
                'title' => $ad->getTranslations('title'),
                'description' => $ad->getTranslations('description'),
                'image' => $this->uploadFilesService->getFullUrl($ad->image),
                'link' => $ad->link,
                'type' => $ad->type,
                'start_date' => $ad->start_date,
                'end_date' => $ad->end_date,
                'is_active' => (bool) $ad->is_active,
                'order' => $ad->order,
            ],
            'Ad retrieved successfully'
        );
    }

    public function update(UpdateAdRequest $request, int $id): JsonResponse
    {
        $validated = $request->validated();

        if ($request->hasFile('image')) {
            $validated['image'] = $this->uploadFilesService->uploadImage($request->file('image'), 'ads');
        }

        $ad = $this->adService->updateAd($id, $validated);

        if (! $ad) {
            return notFoundResponse('Ad not found');
        }

        return successResponse($ad, 'Ad updated successfully');
    }

    public function destroy(int $id): JsonResponse
    {
        $deleted = $this->adService->deleteAd($id);

        if (! $deleted) {
            return notFoundResponse('Ad not found');
        }

        return successResponse(null, 'Ad deleted successfully');
    }

    public function toggleStatus(int $id): JsonResponse
    {
        $ad = $this->adService->getAdById($id);

        if (! $ad) {
            return notFoundResponse('Ad not found');
        }

        $ad = $this->adService->updateAd($id, ['is_active' => ! $ad->is_active]);

        return successResponse($ad, 'Ad status updated successfully');
    }

    public function statistics(): JsonResponse
    {
        $stats = $this->adService->getStatistics();

        return successResponse($stats, 'Ad statistics retrieved successfully');
    }
}
