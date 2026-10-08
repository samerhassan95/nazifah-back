<?php

/**
 * Script to diagnose and fix piece-service-branch associations
 * 
 * Problem: Pieces added to branches with services and additional services are not showing
 * when creating orders in the app.
 * 
 * Root cause: Missing or incorrect branch_id in service_piece and service_addition_piece pivot tables
 * 
 * Run this script to:
 * 1. Diagnose the issue
 * 2. Fix missing branch_id associations
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "==================================================\n";
echo "Piece-Service-Branch Association Diagnostic Tool\n";
echo "==================================================\n\n";

// Get all branches with their pieces
$branches = DB::table('branches')
    ->where('is_active', true)
    ->get(['id', 'vendor_id']);

foreach ($branches as $branch) {
    echo "Checking Branch ID: {$branch->id} (Vendor: {$branch->vendor_id})\n";
    echo str_repeat('-', 50) . "\n";
    
    // Get pieces assigned to this branch
    $pieces = DB::table('branch_piece')
        ->where('branch_id', $branch->id)
        ->where('is_active', true)
        ->get(['piece_id']);
    
    if ($pieces->isEmpty()) {
        echo "  No pieces assigned to this branch\n\n";
        continue;
    }
    
    echo "  Found " . $pieces->count() . " pieces assigned to this branch\n";
    
    foreach ($pieces as $pieceRow) {
        $pieceId = $pieceRow->piece_id;
        
        // Check if piece is active
        $piece = DB::table('pieces')->where('id', $pieceId)->first();
        if (!$piece || !$piece->is_active) {
            echo "  ⚠ Piece ID {$pieceId}: INACTIVE or NOT FOUND\n";
            continue;
        }
        
        // Check service_piece associations
        $servicePieces = DB::table('service_piece')
            ->where('piece_id', $pieceId)
            ->get();
        
        $servicePiecesForBranch = DB::table('service_piece')
            ->where('piece_id', $pieceId)
            ->where('branch_id', $branch->id)
            ->count();
        
        if ($servicePieces->isEmpty()) {
            echo "  ⚠ Piece ID {$pieceId}: NO SERVICES assigned\n";
        } elseif ($servicePiecesForBranch === 0) {
            echo "  ❌ Piece ID {$pieceId}: Has " . $servicePieces->count() . " service(s) but NONE for branch {$branch->id}\n";
            echo "     Current service_piece rows:\n";
            foreach ($servicePieces as $sp) {
                echo "       - service_id: {$sp->service_id}, branch_id: " . ($sp->branch_id ?? 'NULL') . ", price: {$sp->price}\n";
            }
        } else {
            echo "  ✓ Piece ID {$pieceId}: Has {$servicePiecesForBranch} service(s) for this branch\n";
        }
        
        // Check service_addition_piece associations
        $additionalServices = DB::table('service_addition_piece')
            ->where('piece_id', $pieceId)
            ->get();
        
        $additionalServicesForBranch = DB::table('service_addition_piece')
            ->where('piece_id', $pieceId)
            ->where('branch_id', $branch->id)
            ->count();
        
        if ($additionalServices->isEmpty()) {
            echo "  ℹ Piece ID {$pieceId}: No additional services\n";
        } elseif ($additionalServicesForBranch === 0) {
            echo "  ❌ Piece ID {$pieceId}: Has " . $additionalServices->count() . " additional service(s) but NONE for branch {$branch->id}\n";
            echo "     Current service_addition_piece rows:\n";
            foreach ($additionalServices as $sap) {
                echo "       - service_addition_id: {$sap->service_addition_id}, branch_id: " . ($sap->branch_id ?? 'NULL') . ", price: {$sap->price}\n";
            }
        } else {
            echo "  ✓ Piece ID {$pieceId}: Has {$additionalServicesForBranch} additional service(s) for this branch\n";
        }
    }
    
    echo "\n";
}

echo "\n==================================================\n";
echo "Would you like to fix the missing associations? (yes/no): ";
$handle = fopen("php://stdin", "r");
$line = fgets($handle);
$answer = strtolower(trim($line));
fclose($handle);

if ($answer !== 'yes' && $answer !== 'y') {
    echo "Exiting without making changes.\n";
    exit(0);
}

echo "\n==================================================\n";
echo "Fixing Missing Associations\n";
echo "==================================================\n\n";

$fixedCount = 0;

foreach ($branches as $branch) {
    $pieces = DB::table('branch_piece')
        ->where('branch_id', $branch->id)
        ->where('is_active', true)
        ->pluck('piece_id');
    
    if ($pieces->isEmpty()) {
        continue;
    }
    
    foreach ($pieces as $pieceId) {
        // Fix service_piece rows with NULL or wrong branch_id
        $servicePiecesToFix = DB::table('service_piece')
            ->where('piece_id', $pieceId)
            ->where(function($q) use ($branch) {
                $q->whereNull('branch_id')
                  ->orWhere('branch_id', '!=', $branch->id);
            })
            ->get();
        
        foreach ($servicePiecesToFix as $sp) {
            // Check if the service is actually available at this branch
            $serviceAtBranch = DB::table('branch_service')
                ->where('branch_id', $branch->id)
                ->where('service_id', $sp->service_id)
                ->where('is_active', true)
                ->exists();
            
            if (!$serviceAtBranch) {
                continue; // Skip if service not available at branch
            }
            
            // Check if a row already exists for this branch
            $exists = DB::table('service_piece')
                ->where('service_id', $sp->service_id)
                ->where('piece_id', $pieceId)
                ->where('branch_id', $branch->id)
                ->exists();
            
            if (!$exists) {
                // Insert new row with correct branch_id
                DB::table('service_piece')->insert([
                    'service_id' => $sp->service_id,
                    'piece_id' => $pieceId,
                    'branch_id' => $branch->id,
                    'price' => $sp->price,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                
                echo "✓ Fixed service_piece: piece {$pieceId}, service {$sp->service_id}, branch {$branch->id}\n";
                $fixedCount++;
            }
        }
        
        // Fix service_addition_piece rows
        $additionalServicesToFix = DB::table('service_addition_piece')
            ->where('piece_id', $pieceId)
            ->where(function($q) use ($branch) {
                $q->whereNull('branch_id')
                  ->orWhere('branch_id', '!=', $branch->id);
            })
            ->get();
        
        foreach ($additionalServicesToFix as $sap) {
            // Check if a row already exists for this branch
            $exists = DB::table('service_addition_piece')
                ->where('service_addition_id', $sap->service_addition_id)
                ->where('piece_id', $pieceId)
                ->where('branch_id', $branch->id)
                ->exists();
            
            if (!$exists) {
                // Insert new row with correct branch_id
                DB::table('service_addition_piece')->insert([
                    'service_addition_id' => $sap->service_addition_id,
                    'piece_id' => $pieceId,
                    'branch_id' => $branch->id,
                    'price' => $sap->price,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                
                echo "✓ Fixed service_addition_piece: piece {$pieceId}, addition {$sap->service_addition_id}, branch {$branch->id}\n";
                $fixedCount++;
            }
        }
    }
}

echo "\n==================================================\n";
echo "Fixed {$fixedCount} associations\n";
echo "==================================================\n";

// Clear cache
echo "\nClearing cache...\n";
Artisan::call('cache:clear');
echo "✓ Cache cleared\n";

echo "\nDone!\n";
