<?php

namespace Modules\BannerOffer\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Modules\BannerOffer\Http\Requests\StoreBannerOfferRequest;
use Modules\BannerOffer\Http\Requests\UpdateBannerOfferRequest;
use Modules\BannerOffer\Http\Resources\BannerOfferResource;
use Modules\BannerOffer\Services\BannerOfferService;

class BannerOfferController extends Controller
{
    public function __construct(private BannerOfferService $bannerOfferService) {}

    public function index(): JsonResponse
    {
        $locale = app()->getLocale();
        $tags = ['banners'];
        $versionKey = getTagVersionKey($tags);
        $cacheKey = "user:v1:banners:v{$versionKey}:{$locale}";

        $bannerOffers = Cache::remember($cacheKey, 3600, function () {
            return $this->bannerOfferService->getAllBannerOffers();
        });

        return successResponse(
            BannerOfferResource::collection($bannerOffers),
            __('banner.banners_retrieved')
        );
    }

    public function store(StoreBannerOfferRequest $request): JsonResponse
    {
        $bannerOffer = $this->bannerOfferService->createBannerOffer($request->validated());

        return successResponse(new BannerOfferResource($bannerOffer), __('banner.banner_created'), 201);
    }

    public function show(int $id): JsonResponse
    {
        $bannerOffer = $this->bannerOfferService->getBannerOfferById($id);
        if (! $bannerOffer) {
            return notFoundResponse(__('banner.banner_not_found'));
        }

        return successResponse(new BannerOfferResource($bannerOffer), __('banner.banners_retrieved'));
    }

    public function update(UpdateBannerOfferRequest $request, int $id): JsonResponse
    {
        $bannerOffer = $this->bannerOfferService->updateBannerOffer($id, $request->validated());
        if (! $bannerOffer) {
            return notFoundResponse(__('banner.banner_not_found'));
        }

        return successResponse(new BannerOfferResource($bannerOffer), __('banner.banner_updated'));
    }

    public function destroy(int $id): JsonResponse
    {
        $deleted = $this->bannerOfferService->deleteBannerOffer($id);
        if (! $deleted) {
            return notFoundResponse(__('banner.banner_not_found'));
        }

        return successResponse(null, __('banner.banner_deleted'));
    }
}
