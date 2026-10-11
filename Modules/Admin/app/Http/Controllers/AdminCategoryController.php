<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Resources\CategoryResource;
use Modules\Category\Models\Category;

class AdminCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Category::with('iconRelation');

        if ($request->has('is_active')) {
            $query->where('is_active', $request->is_active);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where('name', 'like', "%{$search}%");
        }

        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $categories = $query->paginate($request->input('per_page', 15));

        return successResponse(
            CategoryResource::collection($categories),
            __('admin.categories_retrieved')
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'array'],
            'name.ar' => ['required', 'string', 'max:255'],
            'name.en' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'array'],
            'description.ar' => ['nullable', 'string', 'max:1000'],
            'description.en' => ['nullable', 'string', 'max:1000'],
            'icon_id' => 'required|exists:icons,id',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'is_active' => 'boolean',
        ]);

        $validated['is_active'] = $validated['is_active'] ?? true;

        $category = Category::create($validated);
        $category->load('iconRelation');

        return successResponse(
            new CategoryResource($category),
            __('admin.category_created'),
            201
        );
    }

    public function show(int $id): JsonResponse
    {
        $category = Category::with('iconRelation')->find($id);

        if (! $category) {
            return notFoundResponse(__('admin.category_not_found'));
        }

        return successResponse(
            new CategoryResource($category),
            __('admin.category_retrieved')
        );
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $category = Category::find($id);

        if (! $category) {
            return notFoundResponse(__('admin.category_not_found'));
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'array'],
            'name.ar' => ['sometimes', 'string', 'max:255'],
            'name.en' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'array'],
            'description.ar' => ['nullable', 'string', 'max:1000'],
            'description.en' => ['nullable', 'string', 'max:1000'],
            'icon_id' => 'sometimes|required|exists:icons,id',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'is_active' => 'boolean',
        ]);

        $category->update($validated);

        return successResponse(
            new CategoryResource($category->fresh()->load('iconRelation')),
            __('admin.category_updated')
        );
    }

    public function destroy(int $id): JsonResponse
    {
        $category = Category::find($id);

        if (! $category) {
            return notFoundResponse(__('admin.category_not_found'));
        }

        // Check if category has services
        if ($category->services()->count() > 0) {
            return errorResponse(__('admin.category_cannot_delete_has_services'), 400);
        }

        $category->delete();

        return successResponse(
            null,
            __('admin.category_deleted')
        );
    }

    public function toggleStatus(int $id): JsonResponse
    {
        $category = Category::find($id);

        if (! $category) {
            return notFoundResponse(__('admin.category_not_found'));
        }

        $category->is_active = ! $category->is_active;
        $category->save();

        return successResponse(
            new CategoryResource($category->fresh()->load('iconRelation')),
            __('admin.category_status_updated')
        );
    }

    public function statistics(): JsonResponse
    {
        $stats = [
            'total_categories' => Category::count(),
            'active_categories' => Category::where('is_active', true)->count(),
            'inactive_categories' => Category::where('is_active', false)->count(),
        ];

        return successResponse(
            $stats,
            __('admin.category_statistics_retrieved')
        );
    }
}
