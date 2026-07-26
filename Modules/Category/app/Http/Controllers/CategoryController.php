<?php

namespace Modules\Category\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Category\Http\Requests\StoreCategoryRequest;
use Modules\Category\Http\Requests\UpdateCategoryRequest;
use Modules\Category\Http\Resources\CategoryResource;
use Modules\Category\Services\CategoryService;

class CategoryController extends Controller
{
    public function __construct(private CategoryService $categoryService) {}

    public function index(): JsonResponse
    {
        $categories = $this->categoryService->getAllCategories();

        return successResponse(
            CategoryResource::collection($categories),
            __('category.categories_retrieved')
        );
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $category = $this->categoryService->createCategory($request->validated());

        return successResponse(new CategoryResource($category), __('category.category_created'), 201);
    }

    public function show(int $id): JsonResponse
    {
        $category = $this->categoryService->getCategoryById($id);
        if (! $category) {
            return notFoundResponse(__('category.category_not_found'));
        }

        return successResponse(new CategoryResource($category), __('category.category_retrieved'));
    }

    public function update(UpdateCategoryRequest $request, int $id): JsonResponse
    {
        $category = $this->categoryService->updateCategory($id, $request->validated());
        if (! $category) {
            return notFoundResponse(__('category.category_not_found'));
        }

        return successResponse(new CategoryResource($category), __('category.category_updated'));
    }

    public function destroy(int $id): JsonResponse
    {
        $deleted = $this->categoryService->deleteCategory($id);
        if (! $deleted) {
            return notFoundResponse(__('category.category_not_found'));
        }

        return successResponse(null, __('category.category_deleted'));
    }
}
