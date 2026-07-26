<?php

/**
 * Replace vendor permissions and system roles for every vendor.
 *
 * Usage:
 *   php scripts/replace_vendor_roles_and_permissions.php
 *   php scripts/replace_vendor_roles_and_permissions.php --purge-custom
 *
 * Options:
 *   --purge-custom   Also delete custom (non-system) roles and remap employees to Customer Support
 *   --vendor=ID      Only process a single vendor id
 */

$root = dirname(__DIR__);

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Vendor\Models\Vendor;
use Modules\Vendor\Services\VendorSystemRolesSyncService;

$purgeCustom = in_array('--purge-custom', $argv, true);
$vendorId = null;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--vendor=')) {
        $vendorId = (int) substr($arg, strlen('--vendor='));
    }
}

/** @var VendorSystemRolesSyncService $service */
$service = app(VendorSystemRolesSyncService::class);

if (! Illuminate\Support\Facades\Schema::hasTable('vendor_permissions')) {
    fwrite(STDERR, "Table vendor_permissions not found. Run migrations first:\n  php artisan migrate\n");
    exit(1);
}

echo "Syncing vendor permissions...\n";
$permissionCount = $service->syncPermissions();
echo "Permissions synced: {$permissionCount}\n";

if ($vendorId) {
    $vendor = Vendor::find($vendorId);
    if (! $vendor) {
        fwrite(STDERR, "Vendor #{$vendorId} not found.\n");
        exit(1);
    }

    echo "Replacing roles for vendor #{$vendorId}...\n";
    $stats = $service->replaceVendorRoles($vendor, $purgeCustom);
    echo json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
    echo "Done.\n";
    exit(0);
}

echo 'Replacing system roles for all vendors';
echo $purgeCustom ? " (including custom roles)\n" : "\n";

$stats = $service->replaceAllVendors($purgeCustom);

echo "Summary:\n";
echo "  Vendors processed: {$stats['vendors']}\n";
echo "  Roles created: {$stats['roles_created']}\n";
echo "  Roles removed: {$stats['roles_removed']}\n";
echo "  Employees remapped: {$stats['employees_remapped']}\n";
echo "  Branch assignments remapped: {$stats['assignments_remapped']}\n";
if ($purgeCustom) {
    echo "  Custom roles removed: {$stats['custom_roles_removed']}\n";
}

echo "Done.\n";
