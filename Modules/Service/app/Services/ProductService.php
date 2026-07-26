<?php

namespace Modules\Service\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Service\Interfaces\ProductRepositoryInterface;
use Modules\Service\Models\Product;

class ProductService
{
    public function __construct(
        private ProductRepositoryInterface $productRepository
    ) {}

    public function getAllProducts(array $filters = []): LengthAwarePaginator
    {
        return $this->productRepository->all($filters);
    }

    public function getProductById(int $id): ?Product
    {
        return $this->productRepository->find($id);
    }

    public function createProduct(array $data): Product
    {
        return $this->productRepository->create($data);
    }

    public function updateProduct(int $id, array $data): ?Product
    {
        $product = $this->productRepository->find($id);

        if (! $product) {
            return null;
        }

        $this->productRepository->update($product, $data);

        return $product->fresh();
    }

    public function deleteProduct(int $id): bool
    {
        $product = $this->productRepository->find($id);

        if (! $product) {
            return false;
        }

        return $this->productRepository->delete($product);
    }
}
