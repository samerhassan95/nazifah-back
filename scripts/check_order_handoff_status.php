<?php

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

if ($argc < 2) {
    echo "Usage: php check_order_handoff_status.php <order_id>\n";
    exit(1);
}

$orderId = (int)$argv[1];

$order = DB::table('orders')->where('id', $orderId)->first();

if (!$order) {
    echo "Order #{$orderId} not found\n";
    exit(1);
}

echo "==========================================\n";
echo "Order #{$orderId} Handoff Status\n";
echo "==========================================\n\n";

echo "Basic Info:\n";
echo "  Status: {$order->status}\n";
echo "  pickup_at_vendor: " . ($order->pickup_at_vendor ? 'true' : 'false') . "\n";
echo "  delivery_at_vendor: " . ($order->delivery_at_vendor ? 'true' : 'false') . "\n\n";

echo "Handoff Timestamps:\n";
echo "  client_pickup_handoff_at: " . ($order->client_pickup_handoff_at ?? 'NULL') . "\n";
echo "  vendor_pickup_received_at: " . ($order->vendor_pickup_received_at ?? 'NULL') . "\n";
echo "  vendor_handed_to_delivery_at: " . ($order->vendor_handed_to_delivery_at ?? 'NULL') . "\n";
echo "  client_delivery_handoff_at: " . ($order->client_delivery_handoff_at ?? 'NULL') . "\n\n";

echo "Active Handoff:\n";
echo "  active_handoff_type: " . ($order->active_handoff_type ?? 'NULL') . "\n";
echo "  active_handoff_declared_at: " . ($order->active_handoff_declared_at ?? 'NULL') . "\n";
echo "  active_handoff_declared_by_role: " . ($order->active_handoff_declared_by_role ?? 'NULL') . "\n\n";

echo "Legacy Flags:\n";
echo "  can_confirm_pickup_from_driver: " . (($order->can_confirm_pickup_from_driver ?? false) ? 'true' : 'false') . "\n";
echo "  can_confirm_handover_to_delivery: " . (($order->can_confirm_handover_to_delivery ?? false) ? 'true' : 'false') . "\n\n";

// Determine current handoff stage
$status = $order->status;
$pickupAtVendor = (bool)$order->pickup_at_vendor;
$deliveryAtVendor = (bool)$order->delivery_at_vendor;
$currentStage = null;

if (!$pickupAtVendor && !$order->client_pickup_handoff_at) {
    if (in_array($status, ['on_way_to_pickup', 'picked_up'])) {
        $currentStage = 'client_to_driver';
    }
}

if ($pickupAtVendor && !$order->client_pickup_handoff_at) {
    if (in_array($status, ['confirmed', 'payment_confirmed', 'delivered_to_branch'])) {
        $currentStage = 'client_to_laundry';
    }
}

if (!$pickupAtVendor && $order->client_pickup_handoff_at && !$order->vendor_pickup_received_at) {
    if ($status === 'picked_up') {
        $currentStage = 'driver_to_laundry';
    }
}

echo "Determined Stage: " . ($currentStage ?? 'NULL (no active handoff stage)') . "\n\n";

if (!$currentStage) {
    echo "❌ Problem: Order is not in any handoff stage!\n";
    echo "   This is why confirm-handoff is failing.\n\n";
    
    echo "Possible reasons:\n";
    if ($order->vendor_pickup_received_at) {
        echo "  - Order already received by vendor (vendor_pickup_received_at is set)\n";
    }
    if (!$order->client_pickup_handoff_at && $status !== 'on_way_to_pickup' && $status !== 'picked_up') {
        echo "  - Order status is '{$status}' but should be 'on_way_to_pickup' or 'picked_up' for pickup\n";
    }
}

echo "\n";
