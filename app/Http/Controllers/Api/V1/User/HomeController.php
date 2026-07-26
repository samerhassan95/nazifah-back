<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Services\UploadFilesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Ad\Models\Ad;
use Modules\BannerOffer\Models\BannerOffer;
use Modules\Category\Models\Category;
use Modules\Order\Models\Order;

class HomeController extends Controller
{
    protected $uploadFilesService;

    public function __construct(UploadFilesService $uploadFilesService)
    {
        $this->uploadFilesService = $uploadFilesService;
    }

    /**
     * Get authenticated user data
     */
    public function getUserData(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return unauthorizedResponse('User not authenticated');
        }

        // Check if user is a driver - drivers cannot access this endpoint
        if ($user instanceof \Modules\Driver\Models\Driver) {
            return errorResponse('This endpoint is only available for clients', null, 403);
        }

        // Get user's default address
        $defaultAddress = $user->defaultAddress();

        $addressData = [
            'text' => null,
            'latitude' => null,
            'longitude' => null,
        ];

        if ($defaultAddress) {
            $addressData = [
                'id' => $defaultAddress->id,
                'text' => $defaultAddress->address_text,
                'latitude' => $defaultAddress->latitude,
                'longitude' => $defaultAddress->longitude,
                'address_label' => $defaultAddress->address_label,
                'building_number' => $defaultAddress->building_number,
                'street_name' => $defaultAddress->street_name,
                'district' => $defaultAddress->district,
                'city' => $defaultAddress->city,
                'postal_code' => $defaultAddress->postal_code,
                'zone_id' => $defaultAddress->zone_id,
                'zone_name' => $defaultAddress->zone ? $defaultAddress->zone->name : null,
                'is_default' => (bool) $defaultAddress->is_default,
            ];
        }

        // Order statistics for the authenticated client (single grouped query)
        $statusCounts = Order::where('client_id', $user->id)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $orderStatistics = [
            'total' => (int) $statusCounts->sum(),
            'completed' => (int) ($statusCounts[OrderStatus::COMPLETED->value] ?? 0),
            'cancelled' => (int) ($statusCounts[OrderStatus::CANCELLED->value] ?? 0),
        ];

        return successResponse([
            'user' => [
                'id' => $user->id,
                'full_name' => $user->full_name,
                'email' => $user->email,
                'phone' => $user->phone,
                'image' => $this->uploadFilesService->getFullUrl($user->image),
                'address' => $addressData,
            ],
            'order_statistics' => $orderStatistics,
        ], 'User data retrieved successfully');
    }

    /**
     * Get slider/banner images
     */
    public function getSlider(Request $request): JsonResponse
    {
        $query = BannerOffer::where('is_active', true)
            ->orderBy('order', 'asc');

        $sliders = $query->paginate($request->per_page ?? 15);

        $sliders->getCollection()->transform(function ($slider) {
            return [
                'id' => $slider->id,
                'image' => $this->uploadFilesService->getFullUrl($slider->image),
                'type' => $slider->type ?? 'banner',
                'title' => $slider->title,
                'link' => $slider->link,
            ];
        });

        return successResponse($sliders, 'Slider images retrieved successfully');
    }

    /**
     * Get departments (service categories)
     */
    public function getDepartments(Request $request): JsonResponse
    {
        $query = Category::where('is_active', true)
            ->with('iconRelation')
            ->orderBy('order', 'asc');

        $departments = $query->paginate($request->per_page ?? 15);

        $departments->getCollection()->transform(function ($department) {
            return [
                'id' => $department->id,
                'title' => $department->name,
                'image' => $this->uploadFilesService->getFullUrl($department->image),
                'icon' => $department->iconRelation ? $this->uploadFilesService->getFullUrl($department->iconRelation->path) : null,
                'description' => $department->description,
            ];
        });

        return successResponse($departments, 'Departments retrieved successfully');
    }

    /**
     * Get best/featured laundries
     */
    public function getBestLaundries(Request $request): JsonResponse
    {
        $user = $request->user();

        // Check if user is a driver - drivers cannot access this endpoint
        if ($user && $user instanceof \Modules\Driver\Models\Driver) {
            return errorResponse('This endpoint is only available for clients', null, 403);
        }

        $filters = resolveClientBranchFiltersFromRequest($request);
        $zoneId = $filters['zone_id'];
        $lat = $filters['latitude'];
        $lng = $filters['longitude'];

        $baseQuery = \Modules\Branch\Models\Branch::where('is_active', true)
            ->whereHas('vendor', function ($q) {
                $q->where('is_active', true)
                    ->where(function ($q2) {
                        $q2->where('is_banned', false)->orWhereNull('is_banned');
                    });
            })
            ->with(['vendor', 'workingHourShifts']);

        $zone = null;
        if ($zoneId) {
            $zone = \Modules\Zone\Models\Zone::where('id', $zoneId)->where('is_active', true)->first();
        }

        $query = clone $baseQuery;
        if ($zone) {
            $query->where('zone_id', $zone->id);
        } elseif ($zoneId) {
            // If zoneId was provided but not found/active, return empty result or handle accordingly
            // Here we'll return an empty query result by using a whereRaw(0)
            $query->whereRaw('1 = 0');
        }

        // Calculate distance if coordinates are available
        if ($lat !== null && $lng !== null) {
            $query->selectRaw('branches.*,
                (6371 * acos(cos(radians(?)) * cos(radians(branches.latitude)) *
                cos(radians(branches.longitude) - radians(?)) + sin(radians(?)) *
                sin(radians(branches.latitude)))) AS distance', [$lat, $lng, $lat])
                ->orderBy('distance');
        } else {
            // Order by rating (highest first), then by name
            $query->orderBy('rating', 'desc')
                ->orderBy('name', 'asc');
        }

        $laundries = $query->paginate($request->per_page ?? 15);

        $laundries->getCollection()->transform(function ($branch) {
            $locale = app()->getLocale();

            return [
                'id' => $branch->id,
                'vendor_id' => $branch->vendor_id,
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
                'distance' => isset($branch->distance) ? round($branch->distance, 2).' km' : null,
                'home_pickup' => (bool) $branch->home_pickup,
                'self_dropoff' => (bool) $branch->self_dropoff,
                'home_delivery' => (bool) $branch->home_delivery,
                'self_pickup' => (bool) $branch->self_pickup,
                'delivery_price_per_km' => (float) ($branch->vendor->delivery_price_per_km ?? 0),
                'vendor' => [
                    'id' => $branch->vendor->id,
                    'name' => $branch->vendor->getTranslation('name', $locale),
                    'logo' => $this->uploadFilesService->getFullUrl($branch->vendor->logo),
                    'cover_image' => $this->uploadFilesService->getFullUrl($branch->vendor->cover_image),
                    'phone' => $branch->vendor->phone,
                    'delivery_price_per_km' => (float) ($branch->vendor->delivery_price_per_km ?? 0),
                ],
                'zone' => [
                    'id' => $branch->zone_id,
                    'name' => $branch->zone ? $branch->zone->name : null,
                ],
            ];
        });

        return successResponse($laundries, 'Best laundries retrieved successfully');
    }

    /**
     * Get advertisements
     */
    public function getAds(Request $request): JsonResponse
    {
        $query = Ad::where('is_active', true)
            ->where('start_date', '<=', now())
            ->where('end_date', '>=', now())
            ->orderBy('order', 'asc');

        $ads = $query->paginate($request->per_page ?? 15);

        $ads->getCollection()->transform(function ($ad) {
            return [
                'id' => $ad->id,
                'title' => $ad->title,
                'image' => $this->uploadFilesService->getFullUrl($ad->image),
                'link' => $ad->link,
                'type' => $ad->type,
            ];
        });

        return successResponse($ads, 'Ads retrieved successfully');
    }
}
