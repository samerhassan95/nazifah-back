-- ==================================================================================
-- Fix Piece-Service-Branch Associations
-- ==================================================================================
-- Problem: Pieces added to branches with services and additional services 
-- are not showing when creating orders in the app
--
-- Root Cause: The service_piece and service_addition_piece tables require branch_id
-- to match the branch where the piece is being used, but sometimes rows are created
-- with NULL or wrong branch_id values
--
-- This script diagnoses and fixes the issue
-- ==================================================================================

-- Step 1: Diagnose the problem
-- ==================================================================================

-- Show pieces at branches that have NO service associations for that branch
SELECT 
    bp.branch_id,
    bp.piece_id,
    p.name as piece_name,
    COUNT(DISTINCT sp.id) as service_piece_rows,
    COUNT(DISTINCT CASE WHEN sp.branch_id = bp.branch_id THEN sp.id END) as service_piece_for_branch
FROM branch_piece bp
INNER JOIN pieces p ON p.id = bp.piece_id
LEFT JOIN service_piece sp ON sp.piece_id = bp.piece_id
WHERE bp.is_active = 1
  AND p.is_active = 1
GROUP BY bp.branch_id, bp.piece_id, p.name
HAVING service_piece_rows > 0 AND service_piece_for_branch = 0
ORDER BY bp.branch_id, bp.piece_id;

-- Show pieces at branches that have NO additional service associations for that branch  
SELECT 
    bp.branch_id,
    bp.piece_id,
    p.name as piece_name,
    COUNT(DISTINCT sap.id) as addition_piece_rows,
    COUNT(DISTINCT CASE WHEN sap.branch_id = bp.branch_id THEN sap.id END) as addition_piece_for_branch
FROM branch_piece bp
INNER JOIN pieces p ON p.id = bp.piece_id
LEFT JOIN service_addition_piece sap ON sap.piece_id = bp.piece_id
WHERE bp.is_active = 1
  AND p.is_active = 1
GROUP BY bp.branch_id, bp.piece_id, p.name
HAVING addition_piece_rows > 0 AND addition_piece_for_branch = 0
ORDER BY bp.branch_id, bp.piece_id;


-- Step 2: Fix service_piece associations
-- ==================================================================================

-- Create missing service_piece rows for pieces at branches
-- Only creates rows where:
-- 1. Piece is assigned to branch (branch_piece)
-- 2. Service exists in service_piece for that piece but not for that branch
-- 3. Service is actually available at that branch (branch_service)
-- 4. No existing row for service+piece+branch combination

INSERT INTO service_piece (service_id, piece_id, branch_id, price, created_at, updated_at)
SELECT DISTINCT
    sp.service_id,
    sp.piece_id,
    bp.branch_id,
    sp.price,
    NOW(),
    NOW()
FROM service_piece sp
INNER JOIN branch_piece bp ON bp.piece_id = sp.piece_id
INNER JOIN branch_service bs ON bs.branch_id = bp.branch_id AND bs.service_id = sp.service_id
INNER JOIN pieces p ON p.id = sp.piece_id
INNER JOIN services s ON s.id = sp.service_id
WHERE bp.is_active = 1
  AND p.is_active = 1
  AND s.is_active = 1
  AND bs.is_active = 1
  AND NOT EXISTS (
      SELECT 1 FROM service_piece sp2
      WHERE sp2.service_id = sp.service_id
        AND sp2.piece_id = sp.piece_id
        AND sp2.branch_id = bp.branch_id
  )
  AND (sp.branch_id IS NULL OR sp.branch_id != bp.branch_id);

-- Show what was fixed
SELECT 'Fixed service_piece associations' as message, ROW_COUNT() as rows_affected;


-- Step 3: Fix service_addition_piece associations  
-- ==================================================================================

-- Create missing service_addition_piece rows for pieces at branches
-- Only creates rows where:
-- 1. Piece is assigned to branch (branch_piece)
-- 2. Additional service exists in service_addition_piece for that piece but not for that branch
-- 3. Additional service is active
-- 4. No existing row for addition+piece+branch combination

INSERT INTO service_addition_piece (service_addition_id, piece_id, branch_id, price, created_at, updated_at)
SELECT DISTINCT
    sap.service_addition_id,
    sap.piece_id,
    bp.branch_id,
    sap.price,
    NOW(),
    NOW()
FROM service_addition_piece sap
INNER JOIN branch_piece bp ON bp.piece_id = sap.piece_id
INNER JOIN pieces p ON p.id = sap.piece_id
INNER JOIN service_additions sa ON sa.id = sap.service_addition_id
WHERE bp.is_active = 1
  AND p.is_active = 1
  AND sa.is_active = 1
  AND NOT EXISTS (
      SELECT 1 FROM service_addition_piece sap2
      WHERE sap2.service_addition_id = sap.service_addition_id
        AND sap2.piece_id = sap.piece_id
        AND sap2.branch_id = bp.branch_id
  )
  AND (sap.branch_id IS NULL OR sap.branch_id != bp.branch_id);

-- Show what was fixed
SELECT 'Fixed service_addition_piece associations' as message, ROW_COUNT() as rows_affected;


-- Step 4: Verify the fix
-- ==================================================================================

-- After running the fix, these queries should return 0 rows:

-- Check for pieces with services but none for their branch
SELECT 
    bp.branch_id,
    bp.piece_id,
    p.name as piece_name,
    'Missing service_piece for branch' as issue
FROM branch_piece bp
INNER JOIN pieces p ON p.id = bp.piece_id
INNER JOIN service_piece sp ON sp.piece_id = bp.piece_id
WHERE bp.is_active = 1
  AND p.is_active = 1
  AND NOT EXISTS (
      SELECT 1 FROM service_piece sp2
      WHERE sp2.piece_id = bp.piece_id
        AND sp2.branch_id = bp.branch_id
  )
GROUP BY bp.branch_id, bp.piece_id, p.name;

-- Check for pieces with additional services but none for their branch
SELECT 
    bp.branch_id,
    bp.piece_id,
    p.name as piece_name,
    'Missing service_addition_piece for branch' as issue
FROM branch_piece bp
INNER JOIN pieces p ON p.id = bp.piece_id
INNER JOIN service_addition_piece sap ON sap.piece_id = bp.piece_id
WHERE bp.is_active = 1
  AND p.is_active = 1
  AND NOT EXISTS (
      SELECT 1 FROM service_addition_piece sap2
      WHERE sap2.piece_id = bp.piece_id
        AND sap2.branch_id = bp.branch_id
  )
GROUP BY bp.branch_id, bp.piece_id, p.name;

-- ==================================================================================
-- Note: After running this script, clear the Laravel cache:
-- php artisan cache:clear
-- ==================================================================================
