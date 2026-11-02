<?php

namespace Modules\Vendor\Http\Controllers\Api\V1\LaundryDashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\Admin\Models\Icon;
use Modules\Service\Models\Service;
use Modules\Service\Support\ServiceVendorOffering;
use Modules\Vendor\Support\LaundryDashboardResponse;

/**
 * Laundry Dashboard → Operations Management → Services
 *
 * - The grid shows the services this laundry has enabled (vendor_service).
 * - The "Add service" modal lists the ADMIN catalog (services table) so the laundry can tick several.
 */
class ServicesController extends Controller
{
    use LaundryDashboardResponse;

    /**
     * GET /services
     * Grid: services in this laundry's catalog (active and inactive, so the toggle can flip them).
     */
    public function index(Request $request): JsonResponse
    {
        $vendorId = $this->vendorId();

        $services = Service::with('category')
            ->whereHas('vendors', fn ($q) => $q->where('vendors.id', $vendorId))
            ->orderBy('order')
            ->orderBy('id')
            ->get()
            ->map(fn (Service $s) => $this->format($s, $vendorId));

        return $this->ok($services, 'تم جلب الخدمات بنجاح', 'Services retrieved successfully');
    }

    /**
     * GET /services/catalog
     * "Add service" modal: every active service defined by the admin.
     * `is_selected` = already enabled for this laundry, `is_active` = its current toggle state.
     */
    public function catalog(Request $request): JsonResponse
    {
        $vendorId = $this->vendorId();

        $services = Service::with('category')
            ->where('services.is_active', true)
            ->orderBy('order')
            ->orderBy('id')
            ->get()
            ->map(fn (Service $s) => $this->format($s, $vendorId));

        return $this->ok($services, 'تم جلب خدمات الأدمن بنجاح', 'Admin services retrieved successfully');
    }

    /**
     * POST /services/{id}/toggle-status
     * Optional body: { "is_active": true|false } for an explicit state.
     */
    public function toggleStatus(Request $request, int $serviceId): JsonResponse
    {
        $vendorId = $this->vendorId();

        $service = Service::with('category')->find($serviceId);
        if (! $service) {
            return $this->notFound('الخدمة غير موجودة', 'Service not found');
        }

        $row = ServiceVendorOffering::find($vendorId, $serviceId);
        if (! $row) {
            return $this->notFound('الخدمة غير مضافة لهذه المغسلة', 'Service is not in this laundry catalog');
        }

        $current = (bool) ($row->is_active ?? true);
        $target = $request->has('is_active') ? $request->boolean('is_active') : ! $current;

        if ($target !== $current) {
            ServiceVendorOffering::toggleActive($vendorId, $serviceId);
        }

        flushCacheTags(['services', 'vendors', 'branches']);

        return $this->ok(
            $this->format($service, $vendorId),
            'تم تحديث حالة الخدمة بنجاح',
            'Service status updated successfully'
        );
    }

    /**
     * POST /services/activate   { "service_ids": [1,3,4] }
     * Adds the ticked admin services to this laundry's catalog and activates them.
     */
    public function activate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'service_ids' => ['required', 'array', 'min:1'],
            'service_ids.*' => ['required', 'integer', 'distinct', 'exists:services,id'],
        ]);

        if ($validator->fails()) {
            return $this->invalid($validator->errors());
        }

        $vendorId = $this->vendorId();

        DB::transaction(function () use ($request, $vendorId) {
            Service::whereIn('id', $request->service_ids)
                ->where('is_active', true)
                ->get()
                ->each(function (Service $service) use ($vendorId) {
                    // upsert creates the row if missing; is_active forces it on if it already existed.
                    ServiceVendorOffering::upsert($vendorId, $service, ['is_active' => true]);
                });
        });

        flushCacheTags(['services', 'vendors']);

        $services = Service::with('category')
            ->whereHas('vendors', fn ($q) => $q->where('vendors.id', $vendorId))
            ->orderBy('order')
            ->orderBy('id')
            ->get()
            ->map(fn (Service $s) => $this->format($s, $vendorId));

        return $this->ok($services, 'تم تفعيل الخدمات المحددة بنجاح', 'Selected services activated successfully');
    }

    private function format(Service $service, int $vendorId): array
    {
        $row = ServiceVendorOffering::find($vendorId, $service->id);

        $iconId = ServiceVendorOffering::iconIdForVendor($vendorId, $service);
        $iconUrl = $iconId ? Icon::find($iconId)?->full_path : null;

        $description = ServiceVendorOffering::descriptionForVendor($service, $vendorId, 'ar');
        $descriptionEn = ServiceVendorOffering::descriptionForVendor($service, $vendorId, 'en');

        return [
            'id' => $service->id,
            'category_id' => $service->category_id,
            'service_name' => ServiceVendorOffering::displayNameArrayForVendor($service, $vendorId),
            'description' => ($description || $descriptionEn)
                ? ['ar' => (string) ($description ?: $descriptionEn), 'en' => (string) ($descriptionEn ?: $description)]
                : null,
            'icon_id' => $iconId,
            'icon' => $iconUrl ?: ($service->icon ?: null),
            'is_selected' => $row !== null,
            'is_active' => $row !== null && (bool) ($row->is_active ?? true),
            'rating' => 0,
            'category' => $service->category ? [
                'id' => $service->category->id,
                'name' => $this->bilingual($service->category->getTranslations('name')),
            ] : null,
        ];
    }
}
