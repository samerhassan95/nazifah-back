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
            __('admin.ads_retrieved')
        );
    }

    public function store(StoreAdRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $validated['is_active'] = $validated['is_active'] ?? true;

        if (empty($validated['title']['ar']) && empty($validated['title']['en'])) {
            $validated['title'] = [
                'ar' => 'إعلان ترويجي',
                'en' => 'Promotional Ad',
            ];
        }

        if ($request->hasFile('image')) {
            $validated['image'] = $this->uploadFilesService->uploadFile($request->file('image'), 'ads');
        }

        $ad = $this->adService->createAd($validated);

        return successResponse(new AdResource($ad), __('admin.ad_created'), 201);
    }

    public function show(int $id): JsonResponse
    {
        $ad = $this->adService->getAdById($id);

        if (! $ad) {
            return notFoundResponse(__('admin.ad_not_found'));
        }

        return successResponse(
            [
                'id' => $ad->id,
                'title' => $ad->getTranslations('title'),
                'description' => $ad->getTranslations('description'),
                'image' => $this->uploadFilesService->getFullUrl($ad->image),
                'link' => $ad->link,
                'type' => $ad->type,
                'applications' => $ad->applications ?? [],
                'start_date' => $ad->start_date,
                'end_date' => $ad->end_date,
                'is_active' => (bool) $ad->is_active,
                'order' => $ad->order,
            ],
            __('admin.ad_retrieved')
        );
    }

    public function update(UpdateAdRequest $request, int $id): JsonResponse
    {
        $validated = $request->validated();

        if ($request->hasFile('image')) {
            $validated['image'] = $this->uploadFilesService->uploadFile($request->file('image'), 'ads');
        }

        $ad = $this->adService->updateAd($id, $validated);

        if (! $ad) {
            return notFoundResponse(__('admin.ad_not_found'));
        }

        return successResponse(new AdResource($ad), __('admin.ad_updated'));
    }

    public function destroy(int $id): JsonResponse
    {
        $deleted = $this->adService->deleteAd($id);

        if (! $deleted) {
            return notFoundResponse(__('admin.ad_not_found'));
        }

        return successResponse(null, __('admin.ad_deleted'));
    }

    public function toggleStatus(int $id): JsonResponse
    {
        $ad = $this->adService->getAdById($id);

        if (! $ad) {
            return notFoundResponse(__('admin.ad_not_found'));
        }

        $ad = $this->adService->updateAd($id, ['is_active' => ! $ad->is_active]);

        return successResponse($ad, __('admin.ad_status_updated'));
    }

    public function statistics(): JsonResponse
    {
        $stats = $this->adService->getStatistics();

        return successResponse($stats, __('admin.ad_statistics_retrieved'));
    }
}
