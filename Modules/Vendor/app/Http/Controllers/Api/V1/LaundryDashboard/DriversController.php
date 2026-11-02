<?php

namespace Modules\Vendor\Http\Controllers\Api\V1\LaundryDashboard;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Services\UploadFilesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\Branch\Models\Branch;
use Modules\Driver\Models\Driver;
use Modules\Order\Models\Order;
use Modules\Vendor\Support\LaundryDashboardResponse;

/**
 * Laundry Dashboard → Operations Management → Drivers
 * Prefix: /api/v1/vendor/laundry-dashboard/operations/drivers
 */
class DriversController extends Controller
{
    use LaundryDashboardResponse;

    public function __construct(private UploadFilesService $uploadFilesService) {}

    /**
     * GET /drivers
     * Table row: id, image, name, phone, id_number, status, branch NAME (not id).
     */
    public function index(Request $request): JsonResponse
    {
        $lang = app()->getLocale();

        $drivers = Driver::where('vendor_id', $this->vendorId())
            ->with('branch')
            ->orderByDesc('created_at')
            ->get()
            ->map(function (Driver $driver) use ($lang) {
                $fullName = $this->bilingual($driver->getTranslations('full_name'));

                return [
                    'driver_id' => $driver->id,
                    'name' => $fullName[$lang] ?? $fullName['ar'],
                    'full_name' => $fullName,
                    'email' => $driver->email,
                    'phone' => $driver->phone,
                    'id_number' => $driver->id_number,
                    'rating' => (float) ($driver->rating ?? 0),
                    'image' => $this->uploadFilesService->getFullUrl($driver->image),
                    'is_available' => (bool) $driver->is_available,
                    'branch_id' => $driver->branch_id,
                    'branch_name' => $driver->branch ? $this->bilingual($driver->branch->getTranslations('name')) : null,
                    'branch' => $this->branchPayload($driver->branch),
                    'latitude' => $driver->latitude ? (float) $driver->latitude : null,
                    'longitude' => $driver->longitude ? (float) $driver->longitude : null,
                    'total_orders' => (int) ($driver->total_orders ?? 0),
                ];
            });

        return $this->ok($drivers, 'تم جلب السائقين بنجاح', 'Drivers retrieved successfully');
    }

    /**
     * GET /drivers/{id}
     */
    public function show(Request $request, int $driverId): JsonResponse
    {
        $driver = $this->findDriver($driverId);
        if (! $driver) {
            return $this->notFound('السائق غير موجود', 'Driver not found');
        }

        return $this->ok($this->details($driver), 'تم جلب بيانات السائق بنجاح', 'Driver details retrieved successfully');
    }

    /**
     * POST /drivers  (multipart/form-data or JSON)
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name.ar' => ['required', 'string', 'max:255'],
            'name.en' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('drivers', 'email')->whereNull('deleted_at')],
            'phone_number' => ['required', 'string', 'regex:/^\+?[0-9]{10,15}$/', Rule::unique('drivers', 'phone')->whereNull('deleted_at')],
            'id_number' => ['required', 'string', 'max:50'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'image' => ['nullable', 'file', 'mimes:jpeg,png,jpg,gif,svg', 'max:2048'],
            'image_document' => ['nullable', 'file', 'mimes:pdf,jpeg,png,jpg,gif,svg', 'max:5120'],
        ]);

        if ($validator->fails()) {
            return $this->invalid($validator->errors());
        }

        $vendorId = $this->vendorId();
        $branch = Branch::where('id', $request->branch_id)->where('vendor_id', $vendorId)->first();
        if (! $branch) {
            return $this->invalid(['branch_id' => ['الفرع غير موجود أو لا يتبع المغسلة / Branch not found or not yours']]);
        }

        $driver = Driver::create([
            'vendor_id' => $vendorId,
            'branch_id' => $branch->id,
            'full_name' => ['ar' => $request->input('name.ar'), 'en' => $request->input('name.en')],
            'email' => $request->email,
            'phone' => $request->phone_number,
            'id_number' => $request->id_number,
            'image' => $request->hasFile('image')
                ? $this->uploadFilesService->uploadImage($request->file('image'), 'drivers/images')
                : null,
            'image_document' => $request->hasFile('image_document')
                ? $this->uploadFilesService->uploadFile($request->file('image_document'), 'drivers/documents')
                : null,
            'is_available' => true,
        ]);

        $driver->load('branch');

        return $this->ok($this->details($driver), 'تم إضافة السائق بنجاح', 'Driver created successfully', 201);
    }

    /**
     * PUT /drivers/{id}  (send POST + _method=PUT for multipart)
     * Files are optional: an omitted file keeps the existing one.
     */
    public function update(Request $request, int $driverId): JsonResponse
    {
        $driver = $this->findDriver($driverId);
        if (! $driver) {
            return $this->notFound('السائق غير موجود', 'Driver not found');
        }

        $validator = Validator::make($request->all(), [
            'name.ar' => ['required', 'string', 'max:255'],
            'name.en' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('drivers', 'email')->ignore($driverId)->whereNull('deleted_at')],
            'phone_number' => ['required', 'string', 'regex:/^\+?[0-9]{10,15}$/', Rule::unique('drivers', 'phone')->ignore($driverId)->whereNull('deleted_at')],
            'id_number' => ['required', 'string', 'max:50'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'image' => ['nullable', 'file', 'mimes:jpeg,png,jpg,gif,svg', 'max:2048'],
            'image_document' => ['nullable', 'file', 'mimes:pdf,jpeg,png,jpg,gif,svg', 'max:5120'],
        ]);

        if ($validator->fails()) {
            return $this->invalid($validator->errors());
        }

        $branch = Branch::where('id', $request->branch_id)->where('vendor_id', $this->vendorId())->first();
        if (! $branch) {
            return $this->invalid(['branch_id' => ['الفرع غير موجود أو لا يتبع المغسلة / Branch not found or not yours']]);
        }

        $data = [
            'full_name' => ['ar' => $request->input('name.ar'), 'en' => $request->input('name.en')],
            'email' => $request->email,
            'phone' => $request->phone_number,
            'id_number' => $request->id_number,
            'branch_id' => $branch->id,
        ];

        if ($request->hasFile('image')) {
            $data['image'] = $this->uploadFilesService->uploadImage(
                $request->file('image'),
                'drivers/images',
                $driver->getRawOriginal('image')
            );
        }

        if ($request->hasFile('image_document')) {
            $data['image_document'] = $this->uploadFilesService->uploadFile(
                $request->file('image_document'),
                'drivers/documents',
                $driver->image_document
            );
        }

        $driver->update($data);
        $driver->refresh()->load('branch');

        return $this->ok($this->details($driver), 'تم تحديث بيانات السائق بنجاح', 'Driver updated successfully');
    }

    /**
     * DELETE /drivers/{id}
     * Blocked while the driver still has non-finished orders.
     */
    public function destroy(Request $request, int $driverId): JsonResponse
    {
        $driver = $this->findDriver($driverId);
        if (! $driver) {
            return $this->notFound('السائق غير موجود', 'Driver not found');
        }

        $finished = [OrderStatus::DELIVERED->value, OrderStatus::COMPLETED->value, OrderStatus::CANCELLED->value];
        $branchIds = Branch::where('vendor_id', $this->vendorId())->pluck('id');

        $hasActiveOrders = Order::whereIn('branch_id', $branchIds)
            ->where(function ($q) use ($driverId) {
                $q->where('driver_id', $driverId)
                    ->orWhere('pickup_driver_id', $driverId)
                    ->orWhere('delivery_driver_id', $driverId);
            })
            ->whereNotIn('status', $finished)
            ->exists();

        if ($hasActiveOrders) {
            return $this->fail(
                'لا يمكن حذف سائق لديه طلبات نشطة',
                'Cannot delete a driver with active orders',
                422,
                ['driver_id' => ['لا يمكن حذف سائق لديه طلبات نشطة / Cannot delete a driver with active orders']]
            );
        }

        $driver->tokens()->delete();
        if ($driver->image) {
            $this->uploadFilesService->deleteFile($driver->image);
        }
        $driver->delete();

        return $this->ok(null, 'تم حذف السائق بنجاح', 'Driver deleted successfully');
    }

    /**
     * GET /branches  — branches of this laundry, for the driver form's branch select.
     */
    public function branches(Request $request): JsonResponse
    {
        $branches = Branch::where('vendor_id', $this->vendorId())
            ->orderBy('id')
            ->get()
            ->map(fn (Branch $b) => $this->branchPayload($b));

        return $this->ok($branches, 'تم جلب الفروع بنجاح', 'Branches retrieved successfully');
    }

    private function findDriver(int $driverId): ?Driver
    {
        return Driver::where('id', $driverId)
            ->where('vendor_id', $this->vendorId())
            ->with('branch')
            ->first();
    }

    private function branchPayload(?Branch $branch): ?array
    {
        if (! $branch) {
            return null;
        }

        $location = $this->bilingual($branch->getTranslations('location'));

        return [
            'id' => $branch->id,
            'name' => $this->bilingual($branch->getTranslations('name')),
            'location_text' => $location[app()->getLocale()] ?? $location['ar'],
        ];
    }

    private function details(Driver $driver): array
    {
        $reviews = Order::where('driver_id', $driver->id)
            ->whereNotNull('rating')
            ->with('client')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn ($order) => [
                'user_name' => $order->client?->full_name ?? 'Anonymous',
                'user_image' => $order->client ? $this->uploadFilesService->getFullUrl($order->client->image) : null,
                'rating' => $order->rating,
                'comment' => $order->review,
                'date' => $order->created_at?->format('Y-m-d'),
            ]);

        return [
            'id' => $driver->id,
            'name' => $this->bilingual($driver->getTranslations('full_name')),
            'email' => $driver->email,
            'phone_number' => $driver->phone,
            'national_id' => $driver->id_number,
            'id_number' => $driver->id_number,
            'rating' => (float) ($driver->rating ?? 0),
            'total_orders' => (int) ($driver->total_orders ?? 0),
            'image' => $this->uploadFilesService->getFullUrl($driver->image),
            'image_document' => $this->uploadFilesService->getFullUrl($driver->image_document),
            'is_active' => (bool) $driver->is_available,
            'is_available' => (bool) $driver->is_available,
            'latitude' => $driver->latitude ? (float) $driver->latitude : null,
            'longitude' => $driver->longitude ? (float) $driver->longitude : null,
            'branch' => $this->branchPayload($driver->branch),
            'reviews' => $reviews,
            'created_at' => $driver->created_at?->toIso8601String(),
            'updated_at' => $driver->updated_at?->toIso8601String(),
        ];
    }
}
