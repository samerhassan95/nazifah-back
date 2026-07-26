<?php

namespace Modules\Discount\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Discount\Http\Requests\StoreDiscountRequest;
use Modules\Discount\Http\Requests\UpdateDiscountRequest;
use Modules\Discount\Http\Resources\DiscountResource;
use Modules\Discount\Services\DiscountService;

class DiscountController extends Controller
{
    public function __construct(private DiscountService $discountService) {}

    public function index(): JsonResponse
    {
        $discounts = $this->discountService->getAllDiscounts();

        return successResponse(
            DiscountResource::collection($discounts),
            'Discounts retrieved successfully'
        );
    }

    public function store(StoreDiscountRequest $request): JsonResponse
    {
        $discount = $this->discountService->createDiscount($request->validated());

        return successResponse(new DiscountResource($discount), 'Discount created successfully', 201);
    }

    public function show(int $id): JsonResponse
    {
        $discount = $this->discountService->getDiscountById($id);
        if (! $discount) {
            return notFoundResponse('Discount not found');
        }

        return successResponse(new DiscountResource($discount), 'Discount retrieved successfully');
    }

    public function update(UpdateDiscountRequest $request, int $id): JsonResponse
    {
        $discount = $this->discountService->updateDiscount($id, $request->validated());
        if (! $discount) {
            return notFoundResponse('Discount not found');
        }

        return successResponse(new DiscountResource($discount), 'Discount updated successfully');
    }

    public function destroy(int $id): JsonResponse
    {
        $deleted = $this->discountService->deleteDiscount($id);
        if (! $deleted) {
            return notFoundResponse('Discount not found');
        }

        return successResponse(null, 'Discount deleted successfully');
    }
}
