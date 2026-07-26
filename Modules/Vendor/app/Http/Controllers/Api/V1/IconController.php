<?php

namespace Modules\Vendor\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Enums\IconType;
use Modules\Admin\Http\Resources\IconResource;
use Modules\Admin\Services\IconService;

class IconController extends Controller
{
    public function __construct(private IconService $iconService) {}

    /**
     * Icons not yet used by the authenticated vendor (e.g. ?type=piece).
     */
    public function all(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'type' => ['required', 'string', 'in:'.IconType::valuesString()],
            ]);

            $vendorId = (int) $request->user()->vendor_id;
            $type = $request->input('type');

            $icons = $this->iconService->getUnusedIconsForVendor($vendorId, $type);

            return successResponse(
                IconResource::collection($icons),
                __('vendor.icons_retrieved')
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return validationErrorResponse($e->errors());
        } catch (\Exception $e) {
            return errorResponse($e->getMessage(), 500);
        }
    }
}
