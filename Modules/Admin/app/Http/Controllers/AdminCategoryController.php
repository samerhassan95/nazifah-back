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
            'Categories retrieved successfully'
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
            'Category created successfully',
            201
        );
    }

    public function show(int $id): JsonResponse
    {
        $category = Category::with('iconRelation')->find($id);

        if (! $category) {
            return notFoundResponse('Category not found');
        }

        return successResponse(
            new CategoryResource($category),
            'Category retrieved successfully'
        );
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $category = Category::find($id);

        if (! $category) {
            return notFoundResponse('Category not found');
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
            'Category updated successfully'
        );
    }

    public function destroy(int $id): JsonResponse
    {
        $category = Category::find($id);

        if (! $category) {
            return notFoundResponse('Category not found');
        }

        // Check if category has services
        if ($category->services()->count() > 0) {
            return errorResponse('Cannot delete category with associated services', 400);
        }

        $category->delete();

        return successResponse(
            null,
            'Category deleted successfully'
        );
    }

    public function toggleStatus(int $id): JsonResponse
    {
        $category = Category::find($id);

        if (! $category) {
            return notFoundResponse('Category not found');
        }

        $category->is_active = ! $category->is_active;
        $category->save();

        return successResponse(
            new CategoryResource($category->fresh()->load('iconRelation')),
            'Category status updated successfully'
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
            'Category statistics retrieved successfully'
        );
    }
}
