<?php

namespace Modules\Driver\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Order\Models\Order;

class ReviewsController extends Controller
{
    /**
     * Get driver reviews
     */
    public function index(Request $request): JsonResponse
    {
        $driver = $request->user();

        $query = Order::forDriver($driver->id)
            ->whereNotNull('rating')
            ->with(['client:id,full_name,image']);

        // Filter by rating
        if ($request->has('rating')) {
            $query->where('rating', $request->rating);
        }

        $orders = $query->orderBy('updated_at', 'desc')
            ->paginate($request->per_page ?? 15);

        $data = $orders->map(function ($order) {
            $uploadService = app(\App\Services\UploadFilesService::class);

            return [
                'id' => $order->id,
                'user_name' => $order->client?->full_name ?? 'Unknown',
                'user_img' => $uploadService->getFullUrl($order->client?->image) ?? null,
                'rating' => (int) $order->rating,
                'comment' => $order->review ?? null,
                'date' => $order->updated_at?->format('Y-m-d H:i:s'),
            ];
        });

        // Calculate rating statistics
        $totalReviews = Order::forDriver($driver->id)
            ->whereNotNull('rating')
            ->count();

        $averageRating = Order::forDriver($driver->id)
            ->whereNotNull('rating')
            ->avg('rating');

        $ratingBreakdown = [];
        for ($i = 1; $i <= 5; $i++) {
            $count = Order::forDriver($driver->id)
                ->where('rating', $i)
                ->count();
            $ratingBreakdown[$i] = [
                'rating' => $i,
                'count' => $count,
                'percentage' => $totalReviews > 0 ? round(($count / $totalReviews) * 100, 1) : 0,
            ];
        }

        return successResponse(
            $data,
            'Reviews retrieved successfully',
            200,
            [
                'current_page' => $orders->currentPage(),
                'from' => $orders->firstItem(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'to' => $orders->lastItem(),
                'total' => $orders->total(),
                'statistics' => [
                    'total_reviews' => $totalReviews,
                    'average_rating' => $averageRating ? round($averageRating, 2) : 0,
                    'rating_breakdown' => array_values($ratingBreakdown),
                ],
            ]
        );
    }

    /**
     * Get review statistics only
     */
    public function statistics(Request $request): JsonResponse
    {
        $driver = $request->user();

        $totalReviews = Order::forDriver($driver->id)
            ->whereNotNull('rating')
            ->count();

        $averageRating = Order::forDriver($driver->id)
            ->whereNotNull('rating')
            ->avg('rating');

        $ratingBreakdown = [];
        for ($i = 1; $i <= 5; $i++) {
            $count = Order::forDriver($driver->id)
                ->where('rating', $i)
                ->count();
            $ratingBreakdown[] = [
                'rating' => $i,
                'count' => $count,
                'percentage' => $totalReviews > 0 ? round(($count / $totalReviews) * 100, 1) : 0,
            ];
        }

        return successResponse([
            'total_reviews' => $totalReviews,
            'average_rating' => $averageRating ? round($averageRating, 2) : 0,
            'rating_breakdown' => $ratingBreakdown,
        ], 'Review statistics retrieved successfully');
    }
}
