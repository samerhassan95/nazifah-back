<?php

namespace Modules\Vendor\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\UploadFilesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Modules\Category\Models\Category;

class CategoriesController extends Controller
{
    protected $uploadFilesService;

    public function __construct(UploadFilesService $uploadFilesService)
    {
        $this->uploadFilesService = $uploadFilesService;
    }

    /**
     * List all active categories (read-only for vendors)
     */
    public function index(Request $request): JsonResponse
    {
        $locale = app()->getLocale();
        $tags = ['categories'];
        $versionKey = getTagVersionKey($tags);
        $cacheKey = "vendor:v1:categories:v{$versionKey}:{$locale}";

        $categories = Cache::remember($cacheKey, 86400, function () use ($locale) {
            return Category::where('is_active', true)
                ->get()
                ->map(function ($category) use ($locale) {
                    /** @var \Modules\Category\Models\Category $category */
                    return [
                        'id' => $category->id,
                        'name' => $category->getTranslation('name', $locale),
                        'description' => $category->getTranslation('description', $locale),
                        'icon_id' => $category->icon_id,
                        'icon' => $category->iconRelation ? $category->iconRelation->full_path : null,
                        'image' => $this->uploadFilesService->getFullUrl($category->image),
                        'is_active' => $category->is_active,
                    ];
                });
        });

        return successResponse($categories, __('vendor.categories_retrieved'));
    }
}
