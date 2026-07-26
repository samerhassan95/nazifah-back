<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Create permissions table
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // e.g., 'view_users', 'edit_orders'
            $table->string('display_name_ar');
            $table->string('display_name_en');
            $table->string('group')->nullable(); // e.g., 'users', 'orders', 'settings'
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->timestamps();
        });

        // Create roles table
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // e.g., 'super_admin', 'manager'
            $table->string('display_name_ar');
            $table->string('display_name_en');
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Create role_permission pivot table
        Schema::create('role_permission', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->onDelete('cascade');
            $table->foreignId('permission_id')->constrained('permissions')->onDelete('cascade');
            $table->timestamps();

            $table->unique(['role_id', 'permission_id']);
        });

        // Add role_id to admins table
        Schema::table('admins', function (Blueprint $table) {
            if (! Schema::hasColumn('admins', 'role_id')) {
                $table->foreignId('role_id')->nullable()->after('id')->constrained('roles')->onDelete('set null');
            }
        });

        // Seed default permissions
        $this->seedPermissions();

        // Seed default roles
        $this->seedRoles();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->dropForeign(['role_id']);
            $table->dropColumn('role_id');
        });

        Schema::dropIfExists('role_permission');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }

    /**
     * Seed default permissions
     */
    private function seedPermissions(): void
    {
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
            DB::table('permissions')->insert(array_merge($permission, [
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }

    /**
     * Seed default roles
     */
    private function seedRoles(): void
    {
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
            DB::table('roles')->insert(array_merge($role, [
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }

        // Assign all permissions to super_admin
        $superAdminId = DB::table('roles')->where('name', 'super_admin')->value('id');
        $allPermissions = DB::table('permissions')->pluck('id');

        foreach ($allPermissions as $permissionId) {
            DB::table('role_permission')->insert([
                'role_id' => $superAdminId,
                'permission_id' => $permissionId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Assign view permissions to viewer role
        $viewerId = DB::table('roles')->where('name', 'viewer')->value('id');
        $viewPermissions = DB::table('permissions')->where('name', 'like', 'view_%')->pluck('id');

        foreach ($viewPermissions as $permissionId) {
            DB::table('role_permission')->insert([
                'role_id' => $viewerId,
                'permission_id' => $permissionId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
