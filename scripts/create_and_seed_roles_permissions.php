<?php

/**
 * Setup and seed roles and permissions tables for admins.
 *
 * Usage:
 *   php scripts/create_and_seed_roles_permissions.php
 */

$root = dirname(__DIR__);

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

try {
    DB::beginTransaction();

    // 1. Create permissions table if it doesn't exist
    if (!Schema::hasTable('permissions')) {
        echo "Creating permissions table...\n";
        Schema::create('permissions', function (Blueprint $table) {
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
        echo "permissions table already exists.\n";
    }

    // 2. Create roles table if it doesn't exist
    if (!Schema::hasTable('roles')) {
        echo "Creating roles table...\n";
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('display_name_ar');
            $table->string('display_name_en');
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    } else {
        echo "roles table already exists.\n";
    }

    // 3. Create role_permission pivot table if it doesn't exist
    if (!Schema::hasTable('role_permission')) {
        echo "Creating role_permission pivot table...\n";
        Schema::create('role_permission', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->onDelete('cascade');
            $table->foreignId('permission_id')->constrained('permissions')->onDelete('cascade');
            $table->timestamps();

            $table->unique(['role_id', 'permission_id']);
        });
    } else {
        echo "role_permission pivot table already exists.\n";
    }

    // 4. Add role_id to admins table if it doesn't exist
    if (Schema::hasTable('admins')) {
        if (!Schema::hasColumn('admins', 'role_id')) {
            echo "Adding role_id to admins table...\n";
            Schema::table('admins', function (Blueprint $table) {
                $table->foreignId('role_id')->nullable()->after('id')->constrained('roles')->onDelete('set null');
            });
        } else {
            echo "role_id column already exists on admins table.\n";
        }
    } else {
        echo "Warning: admins table does not exist.\n";
    }

    // 5. Seed default permissions
    echo "Seeding/Updating default permissions...\n";
    $permissions = [
        // Dashboard
        ['name' => 'view_dashboard', 'display_name_ar' => 'عرض لوحة التحكم', 'display_name_en' => 'View Dashboard', 'group' => 'dashboard'],

        // Users Management
        ['name' => 'view_users', 'display_name_ar' => 'عرض المستخدمين', 'display_name_en' => 'View Users', 'group' => 'users'],
        ['name' => 'create_users', 'display_name_ar' => 'إضافة مستخدمين', 'display_name_en' => 'Create Users', 'group' => 'users'],
        ['name' => 'edit_users', 'display_name_ar' => 'تعديل المستخدمين', 'display_name_en' => 'Edit Users', 'group' => 'users'],
        ['name' => 'delete_users', 'display_name_ar' => 'حذف المستخدمين', 'display_name_en' => 'Delete Users', 'group' => 'users'],
        ['name' => 'ban_users', 'display_name_ar' => 'حظر المستخدمين', 'display_name_en' => 'Ban Users', 'group' => 'users'],

        // Admins Management
        ['name' => 'view_admins', 'display_name_ar' => 'عرض المشرفين', 'display_name_en' => 'View Admins', 'group' => 'admins'],
        ['name' => 'create_admins', 'display_name_ar' => 'إضافة مشرفين', 'display_name_en' => 'Create Admins', 'group' => 'admins'],
        ['name' => 'edit_admins', 'display_name_ar' => 'تعديل المشرفين', 'display_name_en' => 'Edit Admins', 'group' => 'admins'],
        ['name' => 'delete_admins', 'display_name_ar' => 'حذف المشرفين', 'display_name_en' => 'Delete Admins', 'group' => 'admins'],

        // Roles & Permissions
        ['name' => 'view_roles', 'display_name_ar' => 'عرض الصلاحيات', 'display_name_en' => 'View Roles', 'group' => 'roles'],
        ['name' => 'create_roles', 'display_name_ar' => 'إضافة صلاحيات', 'display_name_en' => 'Create Roles', 'group' => 'roles'],
        ['name' => 'edit_roles', 'display_name_ar' => 'تعديل الصلاحيات', 'display_name_en' => 'Edit Roles', 'group' => 'roles'],
        ['name' => 'delete_roles', 'display_name_ar' => 'حذف الصلاحيات', 'display_name_en' => 'Delete Roles', 'group' => 'roles'],

        // Orders Management
        ['name' => 'view_orders', 'display_name_ar' => 'عرض الطلبات', 'display_name_en' => 'View Orders', 'group' => 'orders'],
        ['name' => 'create_orders', 'display_name_ar' => 'إضافة طلبات', 'display_name_en' => 'Create Orders', 'group' => 'orders'],
        ['name' => 'edit_orders', 'display_name_ar' => 'تعديل الطلبات', 'display_name_en' => 'Edit Orders', 'group' => 'orders'],
        ['name' => 'delete_orders', 'display_name_ar' => 'حذف الطلبات', 'display_name_en' => 'Delete Orders', 'group' => 'orders'],
        ['name' => 'change_order_status', 'display_name_ar' => 'تغيير حالة الطلب', 'display_name_en' => 'Change Order Status', 'group' => 'orders'],

        // Vendors/Laundries Management
        ['name' => 'view_vendors', 'display_name_ar' => 'عرض المغاسل', 'display_name_en' => 'View Vendors', 'group' => 'vendors'],
        ['name' => 'create_vendors', 'display_name_ar' => 'إضافة مغاسل', 'display_name_en' => 'Create Vendors', 'group' => 'vendors'],
        ['name' => 'edit_vendors', 'display_name_ar' => 'تعديل المغاسل', 'display_name_en' => 'Edit Vendors', 'group' => 'vendors'],
        ['name' => 'delete_vendors', 'display_name_ar' => 'حذف المغاسل', 'display_name_en' => 'Delete Vendors', 'group' => 'vendors'],
        ['name' => 'approve_vendors', 'display_name_ar' => 'الموافقة على المغاسل', 'display_name_en' => 'Approve Vendors', 'group' => 'vendors'],

        // Drivers Management
        ['name' => 'view_drivers', 'display_name_ar' => 'عرض السائقين', 'display_name_en' => 'View Drivers', 'group' => 'drivers'],
        ['name' => 'create_drivers', 'display_name_ar' => 'إضافة سائقين', 'display_name_en' => 'Create Drivers', 'group' => 'drivers'],
        ['name' => 'edit_drivers', 'display_name_ar' => 'تعديل السائقين', 'display_name_en' => 'Edit Drivers', 'group' => 'drivers'],
        ['name' => 'delete_drivers', 'display_name_ar' => 'حذف السائقين', 'display_name_en' => 'Delete Drivers', 'group' => 'drivers'],

        // Services Management
        ['name' => 'view_services', 'display_name_ar' => 'عرض الخدمات', 'display_name_en' => 'View Services', 'group' => 'services'],
        ['name' => 'create_services', 'display_name_ar' => 'إضافة خدمات', 'display_name_en' => 'Create Services', 'group' => 'services'],
        ['name' => 'edit_services', 'display_name_ar' => 'تعديل الخدمات', 'display_name_en' => 'Edit Services', 'group' => 'services'],
        ['name' => 'delete_services', 'display_name_ar' => 'حذف الخدمات', 'display_name_en' => 'Delete Services', 'group' => 'services'],

        // Discounts Management
        ['name' => 'view_discounts', 'display_name_ar' => 'عرض الخصومات', 'display_name_en' => 'View Discounts', 'group' => 'discounts'],
        ['name' => 'create_discounts', 'display_name_ar' => 'إضافة خصومات', 'display_name_en' => 'Create Discounts', 'group' => 'discounts'],
        ['name' => 'edit_discounts', 'display_name_ar' => 'تعديل الخصومات', 'display_name_en' => 'Edit Discounts', 'group' => 'discounts'],
        ['name' => 'delete_discounts', 'display_name_ar' => 'حذف الخصومات', 'display_name_en' => 'Delete Discounts', 'group' => 'discounts'],

        // Notifications
        ['name' => 'view_notifications', 'display_name_ar' => 'عرض الإشعارات', 'display_name_en' => 'View Notifications', 'group' => 'notifications'],
        ['name' => 'send_notifications', 'display_name_ar' => 'إرسال إشعارات', 'display_name_en' => 'Send Notifications', 'group' => 'notifications'],

        // Settings
        ['name' => 'view_settings', 'display_name_ar' => 'عرض الإعدادات', 'display_name_en' => 'View Settings', 'group' => 'settings'],
        ['name' => 'edit_settings', 'display_name_ar' => 'تعديل الإعدادات', 'display_name_en' => 'Edit Settings', 'group' => 'settings'],

        // Reports
        ['name' => 'view_reports', 'display_name_ar' => 'عرض التقارير', 'display_name_en' => 'View Reports', 'group' => 'reports'],
        ['name' => 'export_reports', 'display_name_ar' => 'تصدير التقارير', 'display_name_en' => 'Export Reports', 'group' => 'reports'],

        // Payment Methods
        ['name' => 'view_payment_methods', 'display_name_ar' => 'عرض طرق الدفع', 'display_name_en' => 'View Payment Methods', 'group' => 'payment_methods'],
        ['name' => 'edit_payment_methods', 'display_name_ar' => 'تعديل طرق الدفع', 'display_name_en' => 'Edit Payment Methods', 'group' => 'payment_methods'],
    ];

    foreach ($permissions as $permission) {
        DB::table('permissions')->updateOrInsert(
            ['name' => $permission['name']],
            array_merge($permission, [
                'created_at' => now(),
                'updated_at' => now(),
            ])
        );
    }
    echo count($permissions) . " permissions seeded/updated.\n";

    // 6. Seed default roles
    echo "Seeding/Updating default roles...\n";
    $roles = [
        [
            'name' => 'super_admin',
            'display_name_ar' => 'مدير عام',
            'display_name_en' => 'Super Admin',
            'description_ar' => 'صلاحيات كاملة على النظام',
            'description_en' => 'Full system access',
            'is_active' => true,
        ],
        [
            'name' => 'admin',
            'display_name_ar' => 'مشرف',
            'display_name_en' => 'Admin',
            'description_ar' => 'صلاحيات إدارية عامة',
            'description_en' => 'General administrative access',
            'is_active' => true,
        ],
        [
            'name' => 'manager',
            'display_name_ar' => 'مدير',
            'display_name_en' => 'Manager',
            'description_ar' => 'إدارة الطلبات والمستخدمين',
            'description_en' => 'Manage orders and users',
            'is_active' => true,
        ],
        [
            'name' => 'viewer',
            'display_name_ar' => 'عرض فقط',
            'display_name_en' => 'Viewer',
            'description_ar' => 'عرض البيانات فقط بدون تعديل',
            'description_en' => 'View only access',
            'is_active' => true,
        ],
    ];

    foreach ($roles as $role) {
        DB::table('roles')->updateOrInsert(
            ['name' => $role['name']],
            array_merge($role, [
                'created_at' => now(),
                'updated_at' => now(),
            ])
        );
    }
    echo count($roles) . " roles seeded/updated.\n";

    // 7. Assign permissions to super_admin
    $superAdminId = DB::table('roles')->where('name', 'super_admin')->value('id');
    if ($superAdminId) {
        echo "Mapping all permissions to super_admin...\n";
        $allPermissions = DB::table('permissions')->pluck('id');
        foreach ($allPermissions as $permissionId) {
            DB::table('role_permission')->updateOrInsert(
                ['role_id' => $superAdminId, 'permission_id' => $permissionId],
                ['created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    // 8. Assign view permissions to viewer role
    $viewerId = DB::table('roles')->where('name', 'viewer')->value('id');
    if ($viewerId) {
        echo "Mapping view permissions to viewer...\n";
        $viewPermissions = DB::table('permissions')->where('name', 'like', 'view_%')->pluck('id');
        foreach ($viewPermissions as $permissionId) {
            DB::table('role_permission')->updateOrInsert(
                ['role_id' => $viewerId, 'permission_id' => $permissionId],
                ['created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    DB::commit();
    echo "Migration/Seeding script finished successfully!\n";
} catch (\Exception $e) {
    DB::rollBack();
    fwrite(STDERR, "Error occurred: " . $e->getMessage() . "\n");
    exit(1);
}
