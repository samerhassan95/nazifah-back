-- Fix Branch Vendor Assignment
-- This script assigns vendors to branches that don't have one

-- First, check which branches don't have vendors
SELECT id, name, vendor_id FROM branches WHERE vendor_id IS NULL;

-- Option 1: Assign all branches without vendors to the first vendor
-- UPDATE branches SET vendor_id = (SELECT id FROM vendors ORDER BY id LIMIT 1) WHERE vendor_id IS NULL;

-- Option 2: Assign specific branch to specific vendor
-- Replace 1 with your branch_id and 1 with your vendor_id
-- UPDATE branches SET vendor_id = 1 WHERE id = 1;

-- After running the update, verify the changes
-- SELECT b.id, b.name, b.vendor_id, v.name as vendor_name 
-- FROM branches b 
-- LEFT JOIN vendors v ON b.vendor_id = v.id;
