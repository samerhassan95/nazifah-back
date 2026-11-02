<?php

namespace Modules\Vendor\Http\Controllers\Api\V1\LaundryDashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Enums\IconType;
use Modules\Admin\Models\Icon;
use Modules\Category\Models\Category;
use Modules\Vendor\Support\LaundryDashboardResponse;

/**
 * Laundry Dashboard → Operations Management → Categories (+ icon catalogue)
 */
class CategoriesController extends Controller
{
    use LaundryDashboardResponse;

    /**
     * GET /categories
     * Returns ALL categories defined by the admin (active and inactive), read-only.
     */
    public function index(Request $request): JsonResponse
    {
        $categories = Category::with('iconRelation')
            ->orderBy('order')
            ->orderBy('id')
            ->get()
            ->map(fn (Category $c) => [
                'id' => $c->id,
                'name' => $this->bilingual($c->getTranslations('name')),
                'description' => $c->getTranslations('description') ? $this->bilingual($c->getTranslations('description')) : null,
                'icon_id' => $c->icon_id,
                'icon' => $c->iconRelation?->full_path ?: null,
                'image' => $c->image ? $this->fullUrl($c->image) : null,
                'is_active' => (bool) $c->is_active,
                'created_at' => $c->created_at?->toIso8601String(),
                'updated_at' => $c->updated_at?->toIso8601String(),
            ]);

        return $this->ok($categories, 'تم جلب الفئات بنجاح', 'Categories retrieved successfully');
    }

    /**
     * GET /icons?type=piece|service|additions|category&page=1
     * Icon catalogue used by the icon selector (icon_id).
     */
    public function icons(Request $request): JsonResponse
    {
        $request->validate([
            'type' => ['nullable', 'string', 'in:'.IconType::valuesString()],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Icon::query()->orderBy('id');
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        $paginator = $query->paginate((int) $request->input('per_page', 50));

        $items = collect($paginator->items())->map(fn (Icon $i) => [
            'id' => $i->id,
            'icon' => $i->full_path ?: null,
            'type' => $i->type?->value ?? $i->type,
        ]);

        return $this->ok($items, 'تم جلب الأيقونات بنجاح', 'Icons retrieved successfully', 200, $this->paginationMeta($paginator));
    }

    private function fullUrl(string $path): string
    {
        return str_starts_with($path, 'http') ? $path : config('app.url').$path;
    }
}
