<?php

namespace Modules\Vendor\Services;

class VendorSystemRolesCatalog
{
    /** @return array<int, array<string, mixed>> */
    public static function permissions(): array
    {
        return [
            ['name' => 'view_dashboard', 'display_name_ar' => 'عرض لوحة التحكم', 'display_name_en' => 'View Dashboard', 'group' => 'dashboard'],
            ['name' => 'view_home', 'display_name_ar' => 'عرض الصفحة الرئيسية', 'display_name_en' => 'View Home', 'group' => 'dashboard'],

            ['name' => 'view_employees', 'display_name_ar' => 'عرض الموظفين', 'display_name_en' => 'View Employees', 'group' => 'employees'],
            ['name' => 'manage_employees', 'display_name_ar' => 'إدارة الموظفين', 'display_name_en' => 'Manage Employees', 'group' => 'employees'],

            ['name' => 'view_roles', 'display_name_ar' => 'عرض الأدوار', 'display_name_en' => 'View Roles', 'group' => 'roles'],
            ['name' => 'manage_roles', 'display_name_ar' => 'إدارة الأدوار', 'display_name_en' => 'Manage Roles', 'group' => 'roles'],

            ['name' => 'view_orders', 'display_name_ar' => 'عرض الطلبات', 'display_name_en' => 'View Orders', 'group' => 'orders'],
            ['name' => 'manage_orders', 'display_name_ar' => 'إدارة الطلبات', 'display_name_en' => 'Manage Orders', 'group' => 'orders'],

            ['name' => 'view_branches', 'display_name_ar' => 'عرض الفروع', 'display_name_en' => 'View Branches', 'group' => 'branches'],
            ['name' => 'manage_branches', 'display_name_ar' => 'إدارة الفروع', 'display_name_en' => 'Manage Branches', 'group' => 'branches'],

            ['name' => 'view_services', 'display_name_ar' => 'عرض الخدمات', 'display_name_en' => 'View Services', 'group' => 'services'],
            ['name' => 'manage_services', 'display_name_ar' => 'إدارة الخدمات', 'display_name_en' => 'Manage Services', 'group' => 'services'],

            ['name' => 'view_pieces', 'display_name_ar' => 'عرض القطع', 'display_name_en' => 'View Pieces', 'group' => 'pieces'],
            ['name' => 'manage_pieces', 'display_name_ar' => 'إدارة القطع', 'display_name_en' => 'Manage Pieces', 'group' => 'pieces'],

            ['name' => 'view_additional_services', 'display_name_ar' => 'عرض الخدمات الإضافية', 'display_name_en' => 'View Additional Services', 'group' => 'additional_services'],
            ['name' => 'manage_additional_services', 'display_name_ar' => 'إدارة الخدمات الإضافية', 'display_name_en' => 'Manage Additional Services', 'group' => 'additional_services'],

            ['name' => 'view_drivers', 'display_name_ar' => 'عرض السائقين', 'display_name_en' => 'View Drivers', 'group' => 'drivers'],
            ['name' => 'manage_drivers', 'display_name_ar' => 'إدارة السائقين', 'display_name_en' => 'Manage Drivers', 'group' => 'drivers'],

            ['name' => 'view_wallet', 'display_name_ar' => 'عرض المحفظة', 'display_name_en' => 'View Wallet', 'group' => 'wallet'],
            ['name' => 'manage_wallet', 'display_name_ar' => 'إدارة المحفظة', 'display_name_en' => 'Manage Wallet', 'group' => 'wallet'],

            ['name' => 'view_bank_accounts', 'display_name_ar' => 'عرض الحسابات البنكية', 'display_name_en' => 'View Bank Accounts', 'group' => 'bank_accounts'],
            ['name' => 'manage_bank_accounts', 'display_name_ar' => 'إدارة الحسابات البنكية', 'display_name_en' => 'Manage Bank Accounts', 'group' => 'bank_accounts'],

            ['name' => 'view_subscriptions', 'display_name_ar' => 'عرض الاشتراكات', 'display_name_en' => 'View Subscriptions', 'group' => 'subscriptions'],
            ['name' => 'manage_subscriptions', 'display_name_ar' => 'إدارة الاشتراكات', 'display_name_en' => 'Manage Subscriptions', 'group' => 'subscriptions'],

            ['name' => 'view_reports', 'display_name_ar' => 'عرض التقارير', 'display_name_en' => 'View Reports', 'group' => 'reports'],

            ['name' => 'view_chats', 'display_name_ar' => 'عرض المحادثات', 'display_name_en' => 'View Chats', 'group' => 'chats'],
            ['name' => 'send_messages', 'display_name_ar' => 'إرسال الرسائل', 'display_name_en' => 'Send Messages', 'group' => 'chats'],

            ['name' => 'view_vendor_profile', 'display_name_ar' => 'عرض ملف المؤسسة', 'display_name_en' => 'View Vendor Profile', 'group' => 'vendor_profile'],
            ['name' => 'edit_vendor_profile', 'display_name_ar' => 'تعديل ملف المؤسسة', 'display_name_en' => 'Edit Vendor Profile', 'group' => 'vendor_profile'],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function systemRoles(): array
    {
        return [
            'super_admin' => [
                'display_name_en' => 'Super Admin',
                'display_name_ar' => 'مدير عام',
                'description_en' => 'Has every permission.',
                'description_ar' => 'يمتلك جميع الصلاحيات.',
                'permissions' => 'all',
            ],
            'branch_manager' => [
                'display_name_en' => 'Branch Manager',
                'display_name_ar' => 'مدير فرع',
                'description_en' => 'Responsible for daily branch operations.',
                'description_ar' => 'مسؤول عن العمليات اليومية للفرع.',
                'permissions' => [
                    'manage_branches', 'manage_drivers', 'manage_employees', 'manage_orders', 'send_messages',
                    'view_additional_services', 'view_branches', 'view_chats', 'view_dashboard', 'view_drivers',
                    'view_employees', 'view_home', 'view_orders', 'view_reports', 'view_services', 'view_vendor_profile',
                ],
            ],
            'operations_manager' => [
                'display_name_en' => 'Operations Manager',
                'display_name_ar' => 'مدير العمليات',
                'description_en' => 'Handles logistics, orders, drivers, services, and pieces.',
                'description_ar' => 'يتولى اللوجستيات والطلبات والسائقين والخدمات والقطع.',
                'permissions' => [
                    'manage_additional_services', 'manage_drivers', 'manage_orders', 'manage_pieces', 'manage_services',
                    'send_messages', 'view_additional_services', 'view_branches', 'view_chats', 'view_dashboard',
                    'view_drivers', 'view_employees', 'view_home', 'view_orders', 'view_pieces', 'view_reports',
                    'view_services', 'view_vendor_profile',
                ],
            ],
            'finance_manager' => [
                'display_name_en' => 'Finance Manager',
                'display_name_ar' => 'مدير مالي',
                'description_en' => 'Responsible for financial operations.',
                'description_ar' => 'مسؤول عن العمليات المالية.',
                'permissions' => [
                    'manage_bank_accounts', 'manage_subscriptions', 'manage_wallet', 'view_bank_accounts', 'view_branches',
                    'view_dashboard', 'view_home', 'view_reports', 'view_subscriptions', 'view_vendor_profile', 'view_wallet',
                ],
            ],
            'customer_support' => [
                'display_name_en' => 'Customer Support',
                'display_name_ar' => 'دعم العملاء',
                'description_en' => 'Can monitor orders and communicate with customers but cannot modify business data.',
                'description_ar' => 'يمكنه متابعة الطلبات والتواصل مع العملاء دون تعديل بيانات العمل.',
                'permissions' => [
                    'send_messages', 'view_branches', 'view_chats', 'view_dashboard', 'view_drivers', 'view_home',
                    'view_orders', 'view_reports', 'view_vendor_profile',
                ],
            ],
        ];
    }

    /** @return array<string, string> */
    public static function legacySystemRoleMap(): array
    {
        return [
            'full_access' => 'super_admin',
            'manager' => 'branch_manager',
            'employee' => 'customer_support',
            'super_admin' => 'super_admin',
            'branch_manager' => 'branch_manager',
            'operations_manager' => 'operations_manager',
            'finance_manager' => 'finance_manager',
            'customer_support' => 'customer_support',
        ];
    }

    /** @return string[] */
    public static function systemRoleNames(): array
    {
        return array_keys(self::systemRoles());
    }

    public static function usesManagerLegacyEnum(string $roleName): bool
    {
        return in_array($roleName, ['branch_manager', 'operations_manager', 'super_admin'], true);
    }
}
