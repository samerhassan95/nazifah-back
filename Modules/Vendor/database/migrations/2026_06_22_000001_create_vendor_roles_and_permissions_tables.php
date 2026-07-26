<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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

        Schema::create('vendor_role_permission', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_role_id')->constrained('vendor_roles')->cascadeOnDelete();
            $table->foreignId('vendor_permission_id')->constrained('vendor_permissions')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['vendor_role_id', 'vendor_permission_id'], 'vendor_role_permission_unique');
        });

        Schema::create('vendor_employee_branch_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_employee_id')->constrained('vendor_employees')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('vendor_role_id')->constrained('vendor_roles')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['vendor_employee_id', 'branch_id'], 'vendor_employee_branch_unique');
        });

        Schema::table('vendor_employees', function (Blueprint $table) {
            if (! Schema::hasColumn('vendor_employees', 'vendor_role_id')) {
                $table->foreignId('vendor_role_id')
                    ->nullable()
                    ->after('role')
                    ->constrained('vendor_roles')
                    ->nullOnDelete();
            }
        });

        $this->seedPermissions();
        $this->seedDefaultRolesForExistingVendors();
    }

    public function down(): void
    {
        Schema::table('vendor_employees', function (Blueprint $table) {
            if (Schema::hasColumn('vendor_employees', 'vendor_role_id')) {
                $table->dropForeign(['vendor_role_id']);
                $table->dropColumn('vendor_role_id');
            }
        });

        Schema::dropIfExists('vendor_employee_branch_assignments');
        Schema::dropIfExists('vendor_role_permission');
        Schema::dropIfExists('vendor_roles');
        Schema::dropIfExists('vendor_permissions');
    }

    private function seedDefaultRolesForExistingVendors(): void
    {
        $sync = app(\Modules\Vendor\Services\VendorSystemRolesSyncService::class);

        $vendorIds = DB::table('vendors')->pluck('id');

        foreach ($vendorIds as $vendorId) {
            $vendor = \Modules\Vendor\Models\Vendor::find($vendorId);
            if ($vendor) {
                $sync->seedForVendor($vendor);
            }
        }
    }

    private function seedPermissions(): void
    {
        $sync = app(\Modules\Vendor\Services\VendorSystemRolesSyncService::class);
        $sync->syncPermissions();
    }
};
