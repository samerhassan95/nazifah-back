<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Modules\Admin\Models\Admin;
use Modules\Driver\Models\Driver;
use Modules\Notification\Models\Notification;
use Modules\Order\Models\Order;
use Modules\Vendor\Models\VendorEmployee;

class OrderNotificationService
{
    public function __construct(
        protected UserNotificationService $userNotifications,
    ) {}

    public function sendToClient(
        Order $order,
        string $titleAr,
        string $titleEn,
        string $bodyAr,
        string $bodyEn,
        string $type,
        array $extraData = []
    ): void {
        $order = $order->fresh(['client']);
        if (! $order->client) {
            return;
        }

        $this->sendToUser($order->client, 'client', $order, $titleAr, $titleEn, $bodyAr, $bodyEn, $type, $extraData);
    }

    public function sendToVendorBranch(
        Order $order,
        string $titleAr,
        string $titleEn,
        string $bodyAr,
        string $bodyEn,
        string $type,
        array $extraData = []
    ): void {
        $order = $order->fresh(['branch']);
        if (! $order->branch_id) {
            return;
        }

        foreach ($this->vendorEmployeesForOrder($order) as $employee) {
            $this->sendToUser($employee, 'vendor', $order, $titleAr, $titleEn, $bodyAr, $bodyEn, $type, $extraData);
        }
    }

    public function sendToVendorAndAdmins(
        Order $order,
        string $titleAr,
        string $titleEn,
        string $bodyAr,
        string $bodyEn,
        string $type,
        array $extraData = []
    ): void {
        $this->sendToVendorBranch($order, $titleAr, $titleEn, $bodyAr, $bodyEn, $type, $extraData);
        $this->sendToAdmins($order, $titleAr, $titleEn, $bodyAr, $bodyEn, $type, $extraData);
    }

    public function sendToAdmins(
        Order $order,
        string $titleAr,
        string $titleEn,
        string $bodyAr,
        string $bodyEn,
        string $type,
        array $extraData = []
    ): void {
        foreach (Admin::query()->get() as $admin) {
            $this->sendToUser($admin, 'admin', $order, $titleAr, $titleEn, $bodyAr, $bodyEn, $type, $extraData);
        }
    }

    public function sendToDriver(
        Order $order,
        ?int $driverId,
        string $titleAr,
        string $titleEn,
        string $bodyAr,
        string $bodyEn,
        string $type,
        array $extraData = []
    ): void {
        if (! $driverId || $driverId <= 0) {
            return;
        }

        $driver = Driver::find($driverId);
        if (! $driver) {
            return;
        }

        $this->sendToUser($driver, 'driver', $order, $titleAr, $titleEn, $bodyAr, $bodyEn, $type, $extraData);
    }

    /**
     * @param  'pickup'|'delivery'|'both'  $leg
     */
    public function sendToOrderDrivers(
        Order $order,
        string $leg,
        string $titleAr,
        string $titleEn,
        string $bodyAr,
        string $bodyEn,
        string $type,
        array $extraData = []
    ): void {
        $order = $order->fresh();

        if (in_array($leg, ['pickup', 'both'], true) && $order->pickup_driver_id) {
            $this->sendToDriver($order, (int) $order->pickup_driver_id, $titleAr, $titleEn, $bodyAr, $bodyEn, $type, $extraData);
        }

        if (in_array($leg, ['delivery', 'both'], true) && $order->delivery_driver_id) {
            $this->sendToDriver($order, (int) $order->delivery_driver_id, $titleAr, $titleEn, $bodyAr, $bodyEn, $type, $extraData);
        }
    }

    public function sendToUser(
        Model $user,
        string $userType,
        Order $order,
        string $titleAr,
        string $titleEn,
        string $bodyAr,
        string $bodyEn,
        string $type,
        array $extraData = []
    ): void {
        $this->userNotifications->notify(
            $user,
            $userType,
            $titleAr,
            $titleEn,
            $bodyAr,
            $bodyEn,
            'orders',
            array_merge([
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'notification_type' => $type,
                'order_status' => $order->status,
            ], $extraData)
        );
    }

    public function pushToUser(
        Model $user,
        string $titleAr,
        string $titleEn,
        string $bodyAr,
        string $bodyEn,
        array $data = []
    ): void {
        $this->userNotifications->pushToUser($user, $titleAr, $titleEn, $bodyAr, $bodyEn, $data);
    }

    /**
     * Send "order placed" notifications once checkout payment is settled.
     * Skips if already sent (idempotent for webhook replays).
     */
    public function sendOrderCreatedNotificationsIfNeeded(Order $order): void
    {
        if ($this->orderCreatedNotificationsAlreadySent($order)) {
            return;
        }

        $order = $order->fresh();
        if (! $order) {
            return;
        }

        $num = $order->order_number;

        foreach ([
            'client' => fn () => $this->sendToClient(
                $order,
                'تم استلام طلبك',
                'Order Placed',
                "تم استلام طلبك #{$num} بنجاح.",
                "Your order #{$num} has been placed successfully.",
                'order_placed',
            ),
            'vendor_and_admin' => fn () => $this->sendToVendorAndAdmins(
                $order,
                'طلب جديد',
                'New Order',
                "طلب جديد رقم {$num} من العميل",
                "New order #{$num} from customer",
                'new_order',
            ),
        ] as $target => $callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                try {
                    Log::warning('Order created notification failed', [
                        'order_id' => $order->id,
                        'target' => $target,
                        'error' => $e->getMessage(),
                    ]);
                } catch (\Throwable) {
                }
            }
        }
    }

    public function orderCreatedNotificationsAlreadySent(Order $order): bool
    {
        if (! $order->client_id) {
            return false;
        }

        return Notification::query()
            ->where('user_type', 'client')
            ->where('user_id', $order->client_id)
            ->where('type', 'orders')
            ->where('data->order_id', $order->id)
            ->where('data->notification_type', 'order_placed')
            ->exists();
    }

    /**
     * @return Collection<int, VendorEmployee>
     */
    private function vendorEmployeesForOrder(Order $order): Collection
    {
        $order->loadMissing('branch');

        return VendorEmployee::notifiableForOrderBranch(
            $order->resolveVendorId() ?? 0,
            (int) ($order->branch_id ?? 0)
        );
    }
}
