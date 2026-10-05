<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Admin\Models\Admin;
use Modules\Client\Models\Client;
use Modules\Driver\Models\Driver;
use Modules\Notification\Models\Notification;
use Modules\Order\Models\Order;
use Modules\Payment\Models\PaymentTransaction;
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
        string $userType,
        string $titleAr,
        string $titleEn,
        string $bodyAr,
        string $bodyEn,
        array $data = []
    ): void {
        $this->userNotifications->pushToUser($user, $userType, $titleAr, $titleEn, $bodyAr, $bodyEn, $data);
    }

    /**
     * Send "order placed" notifications once checkout payment is settled.
     * Skips if already sent (idempotent for webhook replays).
     *
     * The gateway webhook, the browser redirect callback, and the client's own
     * active payment-status poll can all land here within milliseconds of each
     * other for the same order. The "already sent?" check below reads the
     * `notifications` table, which is NOT atomic against two concurrent callers
     * both passing the check before either has written its row — so without a
     * lock, vendors (and clients) would get the "new order" notification twice.
     * A cache lock serializes the check-and-send so only one caller wins.
     */
    public function sendOrderCreatedNotificationsIfNeeded(Order $order): void
    {
        if (! $order->id) {
            return;
        }

        // Callers (PendingOrderService::createOrderFromPending() in particular) run
        // this from inside an open DB::transaction(). Locking and checking here
        // immediately was not enough: the lock would be acquired and released, and
        // the Notification::create() row written, all before that OUTER transaction
        // ever committed — so a second, concurrent caller (the gateway webhook, the
        // browser redirect, and the client's own active poll can all race each
        // other) acquired the now-free lock, ran its own "already sent?" SELECT
        // against a connection that couldn't see the first caller's still-uncommitted
        // INSERT, and sent a duplicate. Deferring to afterCommit() guarantees the
        // lock+check+send only runs once the order (and any notification already
        // written by a prior caller) is actually visible to every connection — it
        // runs immediately if there is no open transaction.
        DB::afterCommit(function () use ($order) {
            $lock = Cache::lock("order-created-notifications:{$order->id}", 30);

            try {
                $lock->block(10);
            } catch (\Throwable $e) {
                // Another request is already sending these notifications; don't
                // double up by proceeding without the lock.
                return;
            }

            try {
                if ($this->orderCreatedNotificationsAlreadySent($order)) {
                    return;
                }

                $this->dispatchOrderCreatedNotifications($order);
            } finally {
                $lock->release();
            }
        });
    }

    private function dispatchOrderCreatedNotifications(Order $order): void
    {
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
                "تم استلام طلبك رقم {$num} بنجاح.",
                "Your order number {$num} has been placed successfully.",
                'order_placed',
            ),
            'vendor_and_admin' => fn () => $this->sendToVendorAndAdmins(
                $order,
                'طلب جديد',
                'New Order',
                "طلب جديد رقم {$num} من العميل",
                "New order number {$num} from customer",
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
        if (! $order->id) {
            return false;
        }

        return Notification::query()
            ->where('type', 'orders')
            ->where('data->order_id', (int) $order->id)
            ->whereIn('data->notification_type', ['order_placed', 'new_order'])
            ->exists();
    }

    /**
     * Tell the client their payment genuinely failed (gateway status 'failed' —
     * not merely 'pending'/still-processing). Checkout/webhook settlement only
     * ever notifies on SUCCESS; a client whose card is declined currently gets
     * no push/SMS at all and is left watching the app poll indefinitely. Safe
     * to call from every poll/webhook/redirect that resolves a transaction —
     * locked and checked against the notifications table so only the first
     * caller for a given transaction actually sends it.
     */
    public function notifyClientOfPaymentFailureIfNeeded(PaymentTransaction $transaction, string $message): void
    {
        // Settlement calls this from inside an open transaction; the idempotency
        // check must only run once the transaction's own writes are visible.
        DB::afterCommit(fn () => $this->sendPaymentFailureNotification($transaction->id, $message));
    }

    private function sendPaymentFailureNotification(int $transactionId, string $message): void
    {
        $transaction = PaymentTransaction::find($transactionId);
        if (! $transaction || $transaction->status !== 'failed') {
            return;
        }

        $clientId = $transaction->response_data['client_id'] ?? null;
        if (! $clientId) {
            return;
        }

        $lock = Cache::lock("payment-failed-notification:{$transaction->id}", 30);

        try {
            $lock->block(10);
        } catch (\Throwable $e) {
            return;
        }

        try {
            $alreadySent = Notification::query()
                ->where('type', 'orders')
                ->where('data->transaction_id', $transaction->transaction_id)
                ->where('data->notification_type', 'payment_failed')
                ->exists();

            if ($alreadySent) {
                return;
            }

            $client = Client::find($clientId);
            if (! $client) {
                return;
            }

            $this->userNotifications->notify(
                $client,
                'client',
                'فشل الدفع',
                'Payment Failed',
                $message,
                $message,
                'orders',
                [
                    'transaction_id' => $transaction->transaction_id,
                    'order_number' => $transaction->response_data['order_number'] ?? null,
                    'notification_type' => 'payment_failed',
                    'payment_method' => $transaction->payment_method,
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('Payment failure notification failed', [
                'transaction_id' => $transaction->transaction_id,
                'error' => $e->getMessage(),
            ]);
        } finally {
            $lock->release();
        }
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
