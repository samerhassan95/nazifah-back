<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Admin\Http\Resources\IconResource;
use Modules\Admin\Services\IconService;

class AdminIconController extends Controller
{
    protected IconService $iconService;

    public function __construct(IconService $iconService)
    {
        $this->iconService = $iconService;
    }

    /**
     * Display a listing of icons
     */
    public function index(Request $request)
    {
        try {
            $request->validate([
                'type' => ['nullable', 'string', 'in:'.\Modules\Admin\Enums\IconType::valuesString()],
            ]);

            $perPage = $request->input('per_page', 15);
            $type = $request->input('type');

            $icons = $this->iconService->getIconsPaginated($perPage, $type);

            return successResponse(
                IconResource::collection($icons),
                'Icons retrieved successfully'
            );
        } catch (\Exception $e) {
            return errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Get all icons without pagination
     */
    public function all(Request $request)
    {
        try {
            $request->validate([
                'type' => ['nullable', 'string', 'in:'.\Modules\Admin\Enums\IconType::valuesString()],
            ]);

            $type = $request->input('type');
            $icons = $this->iconService->getAllIcons($type);

            return successResponse(
                IconResource::collection($icons),
                'Icons retrieved successfully'
            );
        } catch (\Exception $e) {
            return errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Store a newly created icon
     */
    public function store(Request $request)
    {
        try {
            $request->validate([
                'icon' => ['required', 'file', 'mimes:jpeg,png,jpg,gif,webp,svg', 'max:2048'],
                'type' => ['required', 'string', 'in:'.\Modules\Admin\Enums\IconType::valuesString()],
            ]);

            $data = [
                'type' => $request->input('type'),
            ];

            if ($request->hasFile('icon')) {
                $data['icon_file'] = $request->file('icon');
            }

            $icon = $this->iconService->createIcon($data);

            return successResponse(
                new IconResource($icon),
                'Icon created successfully',
                201
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return validationErrorResponse($e->errors());
        } catch (\Exception $e) {
            return errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Display the specified icon
     */
    public function show(int $id)
    {
        try {
            $icon = $this->iconService->getIconById($id);

            return successResponse(
                new IconResource($icon),
                'Icon retrieved successfully'
            );
        } catch (\Exception $e) {
            return notFoundResponse($e->getMessage());
        }
    }

    /**
     * Update the specified icon
     */
    public function update(Request $request, int $id)
    {
        try {
            $request->validate([
                'icon' => ['nullable', 'file', 'mimes:jpeg,png,jpg,gif,webp,svg', 'max:2048'],
                'type' => ['nullable', 'string', 'in:'.\Modules\Admin\Enums\IconType::valuesString()],
            ]);

            $data = [];

            if ($request->has('type')) {
                $data['type'] = $request->input('type');
            }

            if ($request->hasFile('icon')) {
                $data['icon_file'] = $request->file('icon');
            }

            $icon = $this->iconService->updateIcon($id, $data);

            return successResponse(
                new IconResource($icon),
                'Icon updated successfully'
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return validationErrorResponse($e->errors());
        } catch (\Exception $e) {
            return errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Remove the specified icon
     */
    public function destroy(int $id)
    {
        try {
            $this->iconService->deleteIcon($id);

            return successResponse(
                null,
                'Icon deleted successfully'
            );
        } catch (\Exception $e) {
            return errorResponse($e->getMessage(), 500);
        }
    }
}
