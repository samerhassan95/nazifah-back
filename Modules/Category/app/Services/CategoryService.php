<?php

namespace Modules\Category\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Category\Interfaces\CategoryRepositoryInterface;
use Modules\Category\Models\Category;

class CategoryService
{
    public function __construct(
        private CategoryRepositoryInterface $categoryRepository
    ) {}

    public function getAllCategorys(array $filters = []): LengthAwarePaginator
    {
        return $this->categoryRepository->all($filters);
    }

    public function getCategoryById(int $id): ?Category
    {
        return $this->categoryRepository->find($id);
    }

    public function createCategory(array $data): Category
    {
        return $this->categoryRepository->create($data);
    }

    public function updateCategory(int $id, array $data): ?Category
    {
        $category = $this->categoryRepository->find($id);

        if (! $category) {
            return null;
        }

        $this->categoryRepository->update($category, $data);

        return $category->fresh();
    }

    public function deleteCategory(int $id): bool
    {
        $category = $this->categoryRepository->find($id);

        if (! $category) {
            return false;
        }

        return $this->categoryRepository->delete($category);
    }
}
