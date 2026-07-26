-- Fix Orphaned Vendor Employees
-- This script identifies and fixes vendor employees that reference non-existent vendors

-- 1. Find orphaned vendor employees (employees with vendor_id that doesn't exist)
SELECT
    ve.id as employee_id,
    ve.vendor_id,
    ve.name as employee_name,
    ve.email,
    ve.phone,
    ve.role
FROM vendor_employees ve
LEFT JOIN vendors v ON ve.vendor_id = v.id
WHERE v.id IS NULL;

-- 2. Count of orphaned employees
SELECT COUNT(*) as orphaned_employees_count
FROM vendor_employees ve
LEFT JOIN vendors v ON ve.vendor_id = v.id
WHERE v.id IS NULL;

-- 3. To delete orphaned employees (UNCOMMENT TO RUN):
-- DELETE ve FROM vendor_employees ve
-- LEFT JOIN vendors v ON ve.vendor_id = v.id
-- WHERE v.id IS NULL;

-- 4. To delete orphaned vendor employee tokens (UNCOMMENT TO RUN):
-- DELETE pat FROM personal_access_tokens pat
-- WHERE pat.tokenable_type = 'Modules\\Vendor\\Models\\VendorEmployee'
-- AND pat.tokenable_id NOT IN (SELECT id FROM vendor_employees);

-- 5. Alternative: Find the specific employee with vendor_id = 61
SELECT * FROM vendor_employees WHERE vendor_id = 61;

-- 6. Check if vendor with id 61 exists
SELECT * FROM vendors WHERE id = 61;
