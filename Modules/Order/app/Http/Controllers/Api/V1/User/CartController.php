<?php

namespace Modules\Order\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Services\UploadFilesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Order\Services\CartService;

/**
 * See E:\nazifah\CART_BACKEND_API.md for the full spec this implements.
 */
class CartController extends Controller
{
    public function __construct(
        private CartService $cartService,
        private UploadFilesService $uploadFilesService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $cart = $this->cartService->getOrCreateCart($request->user());

        return successResponse(
            $this->cartService->formatCart($cart, app()->getLocale()),
            __('cart.cart_retrieved')
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'branch_id' => ['required', 'exists:branches,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.piece_id' => ['required', 'exists:pieces,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.service_ids' => ['required', 'array', 'min:1'],
            'items.*.service_ids.*' => ['integer', 'exists:services,id'],
            'items.*.additional_service_ids' => ['nullable', 'array'],
            'items.*.additional_service_ids.*' => ['integer', 'exists:service_additions,id'],
            'items.*.note' => ['nullable', 'string', 'max:500'],
            // A real file upload, not a path string: the client's local cache path (e.g.
            // Android's /data/user/0/.../cache/scaled_20.png) is meaningless on our server
            // and previously got stored as-is, producing a broken image URL on GET.
            'items.*.image' => ['nullable', 'file', 'mimes:jpeg,png,jpg,gif,webp', 'max:10240'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $items = $request->input('items');
        foreach ($items as $index => &$item) {
            $file = $request->file("items.{$index}.image");
            $item['image'] = $file ? $this->uploadFilesService->uploadImage($file, 'cart/items') : null;
        }
        unset($item);

        $result = $this->cartService->addItems(
            $request->user(),
            (int) $request->branch_id,
            $items,
            app()->getLocale()
        );

        if (! $result['success']) {
            return errorResponse($result['message'], $result['conflict'] ?? null, $result['status']);
        }

        return successResponse($result['data'], __('cart.item_added'), $result['status']);
    }

    public function update(Request $request, int $cartItemId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'quantity' => ['sometimes', 'integer', 'min:1'],
            'service_ids' => ['sometimes', 'array', 'min:1'],
            'service_ids.*' => ['integer', 'exists:services,id'],
            'additional_service_ids' => ['sometimes', 'nullable', 'array'],
            'additional_service_ids.*' => ['integer', 'exists:service_additions,id'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'image' => ['sometimes', 'nullable', 'file', 'mimes:jpeg,png,jpg,gif,webp', 'max:10240'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $changes = $request->only(['quantity', 'service_ids', 'additional_service_ids', 'note']);
        if ($request->hasFile('image')) {
            $changes['image'] = $this->uploadFilesService->uploadImage($request->file('image'), 'cart/items');
        }

        $result = $this->cartService->updateItem(
            $request->user(),
            $cartItemId,
            $changes,
            app()->getLocale()
        );

        if (! $result['success']) {
            return errorResponse($result['message'], null, $result['status']);
        }

        return successResponse($result['data'], __('cart.item_updated'));
    }

    public function destroyItem(Request $request, int $cartItemId): JsonResponse
    {
        $deleted = $this->cartService->deleteItem($request->user(), $cartItemId);

        if (! $deleted) {
            return notFoundResponse(__('cart.item_not_found'));
        }

        return successResponse(null, __('cart.item_deleted'));
    }

    public function clear(Request $request): JsonResponse
    {
        $this->cartService->clear($request->user());

        return successResponse(null, __('cart.cart_cleared'));
    }
}
