<?php

namespace App\Listeners;

use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Admin\Models\AdminSetting;
use Modules\Driver\Models\Driver;
use Modules\Payment\Models\Earning;
use Modules\Payment\Models\PaymentTransaction;

class HandlePaymentDistribution
{
    public function handle(OrderStatusChanged $event): void
    {
        if ($event->newStatus !== OrderStatus::COMPLETED) {
            return;
        }

        try {
            $order = $event->order->fresh(['branch']);

            $transaction = PaymentTransaction::where('order_id', $order->id)
                ->where('status', 'completed')
                ->latest()
                ->first();

            if (! $transaction) {
                return;
            }

            $alreadyDistributed = Earning::where('order_id', $order->id)->exists();
            if ($alreadyDistributed) {
                return;
            }

            $commissionRate = (float) AdminSetting::getValue('platform_commission_percentage', 15);

            $subtotal = (float) $order->total_amount;
            $discount = (float) $order->discount_amount;
            $tax = (float) $order->tax_amount;
            $deliveryFee = (float) $order->delivery_fee;
            $netSubtotal = $subtotal - $discount;

            $platformCommission = round($netSubtotal * $commissionRate / 100, 2);
            $vendorEarning = round($netSubtotal - $platformCommission + $tax, 2);

            DB::transaction(function () use ($order, $platformCommission, $vendorEarning, $deliveryFee) {
                // Platform commission
                Earning::create([
                    'order_id' => $order->id,
                    'earner_type' => 'platform',
                    'earner_id' => null,
                    'amount' => $platformCommission,
                    'type' => 'commission',
                ]);

                // Vendor earnings
                $vendorId = $order->branch?->vendor_id;
                if ($vendorId && $vendorEarning > 0) {
                    Earning::create([
                        'order_id' => $order->id,
                        'earner_type' => 'vendor',
                        'earner_id' => $vendorId,
                        'amount' => $vendorEarning,
                        'type' => 'order_revenue',
                    ]);

                    DB::table('vendors')
                        ->where('id', $vendorId)
                        ->increment('wallet_balance', $vendorEarning);
                }

                // Driver earnings (delivery fee distribution)
                if ($deliveryFee > 0) {
                    $this->distributeDriverFees($order, $deliveryFee);
                }
            });
        } catch (\Throwable $e) {
            Log::error('HandlePaymentDistribution exception', [
                'order_id' => $event->order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function distributeDriverFees($order, float $totalFee): void
    {
        $pickupDriverId = $order->pickup_driver_id;
        $deliveryDriverId = $order->delivery_driver_id;
        $pickupAtVendor = (bool) $order->pickup_at_vendor;
        $deliveryAtVendor = (bool) $order->delivery_at_vendor;

        if ($pickupAtVendor && $deliveryAtVendor) {
            return;
        }

        $driverPayouts = [];

        if ($pickupAtVendor && ! $deliveryAtVendor && $deliveryDriverId) {
            $driverPayouts[$deliveryDriverId] = $totalFee;
        } elseif (! $pickupAtVendor && $deliveryAtVendor && $pickupDriverId) {
            $driverPayouts[$pickupDriverId] = $totalFee;
        } elseif (! $pickupAtVendor && ! $deliveryAtVendor) {
            if ($pickupDriverId && $deliveryDriverId && $pickupDriverId === $deliveryDriverId) {
                $driverPayouts[$pickupDriverId] = $totalFee;
            } else {
                if ($pickupDriverId) {
                    $driverPayouts[$pickupDriverId] = round($totalFee / 2, 2);
                }
                if ($deliveryDriverId) {
                    $driverPayouts[$deliveryDriverId] = round($totalFee / 2, 2);
                }
            }
        }

        foreach ($driverPayouts as $driverId => $amount) {
            Earning::create([
                'order_id' => $order->id,
                'earner_type' => 'driver',
                'earner_id' => $driverId,
                'amount' => $amount,
                'type' => 'delivery_fee',
            ]);

            $driver = Driver::find($driverId);
            if ($driver) {
                $wallet = $driver->getOrCreateWallet();
                $wallet->credit($amount, $order->id, "Delivery fee for order #{$order->order_number}");
            }
        }
    }
}
