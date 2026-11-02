<?php

namespace Modules\Vendor\Http\Controllers\Api\V1\LaundryDashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Service\Models\ServiceAddition;
use Modules\Vendor\Support\LaundryDashboardResponse;

/**
 * Laundry Dashboard → Operations Management → Additional services
 */
class AdditionalServicesController extends Controller
{
    use LaundryDashboardResponse;

    /** GET /additional-services */
    public function index(Request $request): JsonResponse
    {
        $items = ServiceAddition::with('iconRelation')
            ->where('vendor_id', $this->vendorId())
            ->orderBy('id')
            ->get()
            ->map(fn (ServiceAddition $a) => $this->format($a));

        return $this->ok($items, 'تم جلب الخدمات الإضافية بنجاح', 'Additional services retrieved successfully');
    }

    /** POST /additional-services */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), $this->rules());
        if ($validator->fails()) {
            return $this->invalid($validator->errors());
        }

        $addition = ServiceAddition::create([
            'vendor_id' => $this->vendorId(),
            'name' => ['ar' => $request->input('name.ar'), 'en' => $request->input('name.en')],
            'price' => $request->price,
            'icon_id' => $request->icon_id,
            'is_active' => true,
        ]);

        return $this->ok($this->format($addition->fresh('iconRelation')), 'تم إضافة الخدمة الإضافية بنجاح', 'Additional service created successfully', 201);
    }

    /** PUT /additional-services/{id}  (POST + _method=PUT also works) */
    public function update(Request $request, int $id): JsonResponse
    {
        $addition = ServiceAddition::where('id', $id)->where('vendor_id', $this->vendorId())->first();
        if (! $addition) {
            return $this->notFound('الخدمة الإضافية غير موجودة', 'Additional service not found');
        }

        $validator = Validator::make($request->all(), $this->rules());
        if ($validator->fails()) {
            return $this->invalid($validator->errors());
        }

        $addition->update([
            'name' => ['ar' => $request->input('name.ar'), 'en' => $request->input('name.en')],
            'price' => $request->price,
            'icon_id' => $request->icon_id,
        ]);

        return $this->ok($this->format($addition->fresh('iconRelation')), 'تم تحديث الخدمة الإضافية بنجاح', 'Additional service updated successfully');
    }

    /**
     * POST /additional-services/{id}/toggle-status
     * Optional body: { "is_active": true|false }
     */
    public function toggleStatus(Request $request, int $id): JsonResponse
    {
        $addition = ServiceAddition::where('id', $id)->where('vendor_id', $this->vendorId())->first();
        if (! $addition) {
            return $this->notFound('الخدمة الإضافية غير موجودة', 'Additional service not found');
        }

        $addition->update([
            'is_active' => $request->has('is_active') ? $request->boolean('is_active') : ! $addition->is_active,
        ]);

        return $this->ok($this->format($addition->fresh('iconRelation')), 'تم تحديث حالة الخدمة الإضافية بنجاح', 'Additional service status updated successfully');
    }

    private function rules(): array
    {
        return [
            'icon_id' => ['required', 'integer', 'exists:icons,id'],
            'name.ar' => ['required', 'string', 'max:255'],
            'name.en' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:1'],
        ];
    }

    private function format(ServiceAddition $a): array
    {
        return [
            'id' => $a->id,
            'icon_id' => $a->icon_id,
            'icon' => $a->iconRelation?->full_path ?: null,
            'name' => $this->bilingual($a->getTranslations('name')),
            'price' => (float) $a->price,
            'is_active' => (bool) $a->is_active,
        ];
    }
}
