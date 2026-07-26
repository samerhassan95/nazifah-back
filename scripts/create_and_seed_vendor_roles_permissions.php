<?php

/**
 * Setup and seed roles and permissions tables for vendors.
 *
 * Usage:
 *   php scripts/create_and_seed_vendor_roles_permissions.php
 */

$root = dirname(__DIR__);

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Modules\Vendor\Services\VendorSystemRolesSyncService;
use Modules\Vendor\Models\Vendor;

try {
    DB::beginTransaction();

    if (!Schema::hasTable('vendor_permissions')) {
        echo "Creating vendor_permissions table...\n";
        Schema::create('vendor_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('display_name_ar');
            $table->string('display_name_en');
            $table->string('group')->nullable();
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->timestamps();
        });
    } else {
        echo "vendor_permissions table already exists.\n";
    }

    // 2. Create vendor_roles table if it doesn't exist
    if (!Schema::hasTable('vendor_roles')) {
        echo "Creating vendor_roles table...\n";
        Schema::create('vendor_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->string('name');
            $table->string('display_name_ar');
            $table->string('display_name_en');
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['vendor_id', 'name']);
        });
    } else {
        echo "vendor_roles table already exists.\n";
    }

    // 3. Create vendor_role_permission pivot table if it doesn't exist
    if (!Schema::hasTable('vendor_role_permission')) {
        echo "Creating vendor_role_permission pivot table...\n";
        Schema::create('vendor_role_permission', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_role_id')->constrained('vendor_roles')->cascadeOnDelete();
            $table->foreignId('vendor_permission_id')->constrained('vendor_permissions')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['vendor_role_id', 'vendor_permission_id'], 'vendor_role_permission_unique');
        });
    } else {
        echo "vendor_role_permission pivot table already exists.\n";
    }

    // 4. Create vendor_employee_branch_assignments table if it doesn't exist
    if (!Schema::hasTable('vendor_employee_branch_assignments')) {
        echo "Creating vendor_employee_branch_assignments table...\n";
        Schema::create('vendor_employee_branch_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_employee_id')->constrained('vendor_employees')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('vendor_role_id')->constrained('vendor_roles')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['vendor_employee_id', 'branch_id'], 'vendor_employee_branch_unique');
        });
    } else {
        echo "vendor_employee_branch_assignments table already exists.\n";
    }

    // 5. Add vendor_role_id to vendor_employees table if it doesn't exist
    if (Schema::hasTable('vendor_employees')) {
        if (!Schema::hasColumn('vendor_employees', 'vendor_role_id')) {
            echo "Adding vendor_role_id to vendor_employees table...\n";
            Schema::table('vendor_employees', function (Blueprint $table) {
                $table->foreignId('vendor_role_id')
                    ->nullable()
                    ->after('role')
                    ->constrained('vendor_roles')
                    ->nullOnDelete();
            });
        } else {
            echo "vendor_role_id column already exists on vendor_employees table.\n";
        }
    } else {
        echo "Warning: vendor_employees table does not exist.\n";
    }

    DB::commit();

    // 6. Seed and sync roles and permissions
    echo "Syncing/Seeding vendor permissions and roles...\n";
    $sync = app(VendorSystemRolesSyncService::class);
    $permissionCount = $sync->syncPermissions();
    echo "Permissions synced: {$permissionCount}\n";

    echo "Syncing system roles for all vendors...\n";
    $stats = $sync->replaceAllVendors(false);
    
    echo "Summary:\n";
    echo "  Vendors processed: {$stats['vendors']}\n";
    echo "  Roles created: {$stats['roles_created']}\n";
    echo "  Roles removed: {$stats['roles_removed']}\n";
    echo "  Employees remapped: {$stats['employees_remapped']}\n";
    echo "  Branch assignments remapped: {$stats['assignments_remapped']}\n";

    echo "Migration/Seeding script for Vendor finished successfully!\n";
} catch (\Exception $e) {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    fwrite(STDERR, "Error occurred: " . $e->getMessage() . "\n");
    exit(1);
}
