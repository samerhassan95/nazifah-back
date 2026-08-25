<?php

namespace Modules\Admin\Http\Resources;

use App\Enums\OrderStatus;
use App\Services\UploadFilesService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Branch\Models\Branch;
use Modules\Order\Models\Order;

class VendorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $uploadFilesService = app(UploadFilesService::class);

        // Get branch IDs for this vendor
        $branchIds = Branch::where('vendor_id', $this->id)->pluck('id');

        // Get orders statistics through branches
        $totalOrders = Order::whereIn('branch_id', $branchIds)->count();
        $completedOrders = Order::whereIn('branch_id', $branchIds)
            ->where('status', OrderStatus::COMPLETED->value)
            ->count();
        $cancelledOrders = Order::whereIn('branch_id', $branchIds)
            ->where('status', OrderStatus::CANCELLED->value)
            ->count();
        $underImplementationOrders = Order::whereIn('branch_id', $branchIds)
            ->whereIn('status', [
                OrderStatus::PENDING->value,
                OrderStatus::CONFIRMED->value,
                OrderStatus::PICKED_UP->value,
                OrderStatus::DELIVERED_TO_BRANCH->value,
                OrderStatus::DRIVER_DELIVERY_ASSIGNED->value,
                OrderStatus::DRIVER_DELIVERY_ACCEPTED->value,
                OrderStatus::ON_WAY_TO_DELIVERY->value,
            ])
            ->count();

        // Calculate rating from orders
        $ratedOrders = Order::whereIn('branch_id', $branchIds)
            ->whereNotNull('rating')
            ->get();
        $rating = $ratedOrders->count() > 0 ? round($ratedOrders->avg('rating'), 2) : 0;
        $totalReviews = $ratedOrders->count();

        $isDetailView = $request->route() && $request->route()->getName() && str_contains($request->route()->getName(), 'show');
        $locale = app()->getLocale();

        return [
            'id' => $this->id,
            'name' => $isDetailView ? ['ar' => $this->getTranslation('name', 'ar'), 'en' => $this->getTranslation('name', 'en')] : $this->getTranslation('name', $locale),
            'logo' => $uploadFilesService->getFullUrl($this->logo),
            'email' => $this->email,
            'official_number' => $this->official_number,
            'vat_number' => $this->vat_number ?? null,
            'phone' => $this->phone,
            'delivery_price_per_km' => $this->delivery_price_per_km ? (float) $this->delivery_price_per_km : 0,
            'rating' => (float) $rating,
            'total_reviews' => $totalReviews,
            'is_active' => $this->is_active,
            'is_verified' => $this->is_verified,
            'rejection_reason' => $this->rejection_reason,
            'rejected_at' => $this->rejected_at?->toDateTimeString(),
            'is_banned' => $this->is_banned ?? false,
            'ban_reason' => $this->ban_reason,
            'banned_at' => $this->banned_at?->toDateTimeString(),
            'Orders' => [
                'Completed' => $completedOrders,
                'Total' => $totalOrders,
                'Cancelled' => $cancelledOrders,
                'Under_implementation' => $underImplementationOrders,
            ],
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
