<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\UploadFilesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Requests\StoreBannerOfferRequest;
use Modules\Admin\Http\Requests\UpdateBannerOfferRequest;
use Modules\Admin\Http\Resources\BannerOfferResource;
use Modules\BannerOffer\Enums\BannerDestinationType;
use Modules\BannerOffer\Models\BannerOffer;
use Modules\Notification\Enums\UserTargetType;

class AdminBannerOfferController extends Controller
{
    public function __construct(
        private UploadFilesService $uploadFilesService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = BannerOffer::query();

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('destination_type')) {
            $query->where('destination_type', $request->destination_type);
        }

        if ($request->filled('user_target_type')) {
            $query->where('user_target_type', $request->user_target_type);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $bannerOffers = $query->paginate($request->input('per_page', 15));

        return successResponse(
            BannerOfferResource::collection($bannerOffers),
            __('admin.banner_offers_retrieved')
        );
    }

    public function store(StoreBannerOfferRequest $request): JsonResponse
    {
        $validated = $this->preparePayload($request->validated());

        if ($request->hasFile('image')) {
            $validated['image'] = $this->uploadFilesService->uploadImage(
                $request->file('image'),
                'banner-offers'
            );
        }

        $bannerOffer = BannerOffer::create($validated);

        return successResponse(
            new BannerOfferResource($bannerOffer),
            __('admin.banner_offer_created'),
            201
        );
    }

    public function show(int $id): JsonResponse
    {
        $bannerOffer = BannerOffer::find($id);

        if (! $bannerOffer) {
            return notFoundResponse(__('admin.banner_offer_not_found'));
        }

        return successResponse(
            new BannerOfferResource($bannerOffer),
            __('admin.banner_offer_retrieved')
        );
    }

    public function update(UpdateBannerOfferRequest $request, int $id): JsonResponse
    {
        $bannerOffer = BannerOffer::find($id);

        if (! $bannerOffer) {
            return notFoundResponse(__('admin.banner_offer_not_found'));
        }

        $validated = $this->preparePayload($request->validated(), $bannerOffer);

        if ($request->hasFile('image')) {
            $validated['image'] = $this->uploadFilesService->uploadImage(
                $request->file('image'),
                'banner-offers',
                $bannerOffer->getRawOriginal('image')
            );
        }

        $bannerOffer->update($validated);

        return successResponse(
            new BannerOfferResource($bannerOffer->fresh()),
            __('admin.banner_offer_updated')
        );
    }

    public function destroy(int $id): JsonResponse
    {
        $bannerOffer = BannerOffer::find($id);

        if (! $bannerOffer) {
            return notFoundResponse(__('admin.banner_offer_not_found'));
        }

        $bannerOffer->delete();

        return successResponse(null, __('admin.banner_offer_deleted'));
    }

    public function toggleStatus(int $id): JsonResponse
    {
        $bannerOffer = BannerOffer::find($id);

        if (! $bannerOffer) {
            return notFoundResponse(__('admin.banner_offer_not_found'));
        }

        $bannerOffer->is_active = ! $bannerOffer->is_active;
        $bannerOffer->save();

        return successResponse(
            new BannerOfferResource($bannerOffer),
            __('admin.banner_offer_status_updated')
        );
    }

    public function statistics(): JsonResponse
    {
        $stats = [
            'total_banners' => BannerOffer::count(),
            'active_banners' => BannerOffer::where('is_active', true)->count(),
            'inactive_banners' => BannerOffer::where('is_active', false)->count(),
        ];

        return successResponse($stats, __('admin.banner_offer_statistics_retrieved'));
    }

    private function preparePayload(array $validated, ?BannerOffer $existing = null): array
    {
        $validated['is_active'] = $validated['is_active'] ?? ($existing?->is_active ?? true);
        $validated['user_target_type'] = $validated['user_target_type'] ?? UserTargetType::ALL->value;

        if (($validated['user_target_type'] ?? UserTargetType::ALL->value) === UserTargetType::ALL->value) {
            $validated['target_user_ids'] = null;
        }

        if (($validated['destination_type'] ?? null) === BannerDestinationType::EXTERNAL_URL->value) {
            $validated['destination_id'] = null;
        } else {
            $validated['link'] = $validated['link'] ?? null;
        }

        if (empty($validated['title']) && ! empty($validated['destination_type']) && ! empty($validated['destination_id'])) {
            $temp = new BannerOffer([
                'destination_type' => $validated['destination_type'],
                'destination_id' => $validated['destination_id'],
                'link' => $validated['link'] ?? null,
            ]);
            $destinationName = $temp->resolveDestinationName('ar');
            if ($destinationName) {
                $validated['title'] = ['ar' => $destinationName, 'en' => $destinationName];
            }
        }

        return $validated;
    }
}
