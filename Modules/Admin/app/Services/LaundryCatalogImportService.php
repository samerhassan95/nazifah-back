<?php

namespace Modules\Admin\Services;

use Modules\Branch\Models\Branch;
use Modules\Category\Models\Category;
use Modules\Piece\Models\Piece;
use Modules\Service\Models\Service;
use Modules\Service\Models\ServiceAddition;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Service for importing catalog data from Master Catalog Excel v6 (or legacy format).
 * Supports both standalone sheets per screen and full imports.
 *
 * Scopes supported:
 * - 'categories' : Imports 01_الأقسام into global categories.
 * - 'services'   : Imports 02_الخدمات into global services (linked to categories).
 * - 'pieces'     : Imports 03_القطع & 04_ربط_الخدمة_بالقطعة into vendor pieces & branch links.
 *                   Also creates any 02_الخدمات service referenced by the link sheet that
 *                   does not already exist globally, so a piece's services never silently
 *                   end up unlinked just because this scope was used on its own.
 * - 'addons'     : Imports 05_الإضافات into vendor additional services (ServiceAddition).
 * - 'full'       : Imports all available sheets in the workbook.
 */
class LaundryCatalogImportService
{
    private const SECTIONS_SHEET = '01_الأقسام';
    private const SERVICES_SHEET = '02_الخدمات';
    private const PIECES_SHEET = '03_القطع';
    private const SERVICE_PIECE_LINKS_SHEET = '04_ربط_الخدمة_بالقطعة';
    private const ADDONS_SHEET_V6 = '05_الإضافات';
    private const ADDONS_SHEET_LEGACY = '04_الإضافية';
    private const ADDON_LINKS_SHEET_LEGACY = '05_ربط_الإضافية';

    /**
     * @param int[] $branchIds
     */
    public function import(
        string $filePath,
        ?int $vendorId,
        array $branchIds,
        bool $dryRun = true,
        string $scope = 'full'
    ): array {
        $spreadsheet = IOFactory::load($filePath);

        $stats = [
            'categories_created' => 0,
            'categories_skipped' => 0,
            'services_created' => 0,
            'services_skipped' => 0,
            'pieces_created' => 0,
            'pieces_skipped' => 0,
            'addons_created' => 0,
            'addons_skipped' => 0,
            'piece_service_attached' => 0,
            'branch_piece_attached' => 0,
            'branch_piece_skipped' => 0,
        ];

        // 1. Read Sections/Categories if requested
        $sections = in_array($scope, ['categories', 'services', 'pieces', 'full'], true)
            ? $this->sheetRowsOrEmpty($spreadsheet, self::SECTIONS_SHEET, ['Section_ID', 'section_id'])
            : [];

        // 2. Read Services if requested
        $servicesIn = in_array($scope, ['services', 'pieces', 'full'], true)
            ? $this->sheetRowsOrEmpty($spreadsheet, self::SERVICES_SHEET, ['Service_ID', 'service_id'])
            : [];

        // 3. Read Pieces & Links if requested
        $piecesIn = in_array($scope, ['pieces', 'full'], true)
            ? $this->sheetRowsOrEmpty($spreadsheet, self::PIECES_SHEET, ['Piece_ID', 'piece_id'])
            : [];

        $servicePieceLinks = in_array($scope, ['pieces', 'full'], true)
            ? $this->sheetRowsOrEmpty($spreadsheet, self::SERVICE_PIECE_LINKS_SHEET, ['Service_ID', 'service_id'])
            : [];

        // 4. Read Addons if requested
        $addonsIn = [];
        if (in_array($scope, ['addons', 'full'], true)) {
            $addonsIn = $this->sheetRowsOrEmpty($spreadsheet, self::ADDONS_SHEET_V6, ['AddOn_ID', 'addon_id']);
            if (empty($addonsIn)) {
                $addonsIn = $this->sheetRowsOrEmpty($spreadsheet, self::ADDONS_SHEET_LEGACY, ['AddOn_ID', 'addon_id']);
            }
        }

        $run = function () use (
            $sections,
            $servicesIn,
            $piecesIn,
            $servicePieceLinks,
            $addonsIn,
            $vendorId,
            $branchIds,
            &$stats,
            $dryRun,
            $scope
        ) {
            // ==========================================
            // A. Categories (Global)
            // ==========================================
            $existingCategories = Category::all()->keyBy(fn ($c) => trim($c->getTranslation('name', 'ar')));
            $categoryIdMap = [];
            $canCreateCategories = in_array($scope, ['categories', 'full'], true);

            foreach ($sections as $row) {
                $name = trim((string) ($row['اسم القسم'] ?? $row['name'] ?? ''));
                $sectionCode = (string) ($row['Section_ID'] ?? $row['section_id'] ?? '');

                if ($name === '') {
                    continue;
                }

                if (isset($existingCategories[$name])) {
                    if ($sectionCode !== '') {
                        $categoryIdMap[$sectionCode] = $existingCategories[$name]->id;
                    }
                    if ($canCreateCategories) {
                        $stats['categories_skipped']++;
                    }
                    continue;
                }

                if (! $canCreateCategories) {
                    continue;
                }

                if ($dryRun) {
                    if ($sectionCode !== '') {
                        $categoryIdMap[$sectionCode] = 'NEW:' . $sectionCode;
                    }
                    $stats['categories_created']++;
                    continue;
                }

                $cat = Category::create([
                    'name' => ['ar' => $name, 'en' => $name],
                    'order' => (int) ($row['ترتيب العرض'] ?? 0),
                    'is_active' => true,
                ]);

                if ($sectionCode !== '') {
                    $categoryIdMap[$sectionCode] = $cat->id;
                }
                $existingCategories[$name] = $cat;
                $stats['categories_created']++;
            }

            if ($scope === 'categories') {
                return;
            }

            // ==========================================
            // B. Services (Global)
            // ==========================================
            $existingServices = Service::all()->keyBy(fn ($s) => trim($s->getTranslation('service_name', 'ar')));
            $serviceIdMap = [];
            // 'pieces' scope must also be allowed to create missing global services: the
            // branch-level "import pieces" screen only exposes this scope (no separate
            // "import services first" step), so a service referenced by the link sheet
            // that doesn't already exist would otherwise never get a serviceIdMap entry —
            // section D then silently drops that piece-service link with no indication to
            // the admin, leaving the piece's edit screen with no usable services at all.
            $canCreateServices = in_array($scope, ['services', 'pieces', 'full'], true);

            foreach ($servicesIn as $row) {
                $name = trim((string) ($row['اسم الخدمة'] ?? $row['name'] ?? ''));
                $svcCode = (string) ($row['Service_ID'] ?? $row['service_id'] ?? '');
                $sectionCode = (string) ($row['Section_ID'] ?? $row['section_id'] ?? '');

                if ($name === '') {
                    continue;
                }

                if (isset($existingServices[$name])) {
                    if ($svcCode !== '') {
                        $serviceIdMap[$svcCode] = $existingServices[$name]->id;
                    }
                    if ($canCreateServices) {
                        $stats['services_skipped']++;
                    }
                    continue;
                }

                if (! $canCreateServices) {
                    continue;
                }

                $catId = $categoryIdMap[$sectionCode] ?? null;
                if (! is_int($catId)) {
                    // Try to match category by section name if provided in row
                    $sectionName = trim((string) ($row['القسم'] ?? ''));
                    if ($sectionName !== '' && isset($existingCategories[$sectionName])) {
                        $catId = $existingCategories[$sectionName]->id;
                    }
                }

                // If still not found, fallback to the first existing category to prevent SQL not-null violation
                if (! is_int($catId)) {
                    $firstCat = $existingCategories->first();
                    $catId = $firstCat ? $firstCat->id : 1;
                }

                if ($dryRun) {
                    if ($svcCode !== '') {
                        $serviceIdMap[$svcCode] = 'NEW:' . $svcCode;
                    }
                    $stats['services_created']++;
                    continue;
                }

                $svc = Service::create([
                    'service_name' => ['ar' => $name, 'en' => $name],
                    'category_id' => $catId,
                    'order' => (int) ($row['ترتيب الخدمة'] ?? 0),
                    'is_active' => true,
                ]);

                if ($svcCode !== '') {
                    $serviceIdMap[$svcCode] = $svc->id;
                }
                $existingServices[$name] = $svc;
                $stats['services_created']++;
            }

            if ($scope === 'services') {
                return;
            }

            // ==========================================
            // C. Addons / Additional Services (Vendor-Scoped)
            // ==========================================
            if (in_array($scope, ['addons', 'full'], true) && $vendorId) {
                $existingAddons = ServiceAddition::where('vendor_id', $vendorId)->get()
                    ->keyBy(fn ($a) => trim($a->getTranslation('name', 'ar')));

                foreach ($addonsIn as $row) {
                    $name = trim((string) ($row['اسم الإضافة'] ?? $row['اسم الخدمة الإضافية'] ?? $row['name'] ?? ''));
                    if ($name === '') {
                        continue;
                    }

                    if (isset($existingAddons[$name])) {
                        $stats['addons_skipped']++;
                        continue;
                    }

                    if ($dryRun) {
                        $stats['addons_created']++;
                        continue;
                    }

                    $addon = ServiceAddition::create([
                        'vendor_id' => $vendorId,
                        'name' => ['ar' => $name, 'en' => $name],
                        'description' => ! empty($row['ملاحظات']) ? ['ar' => (string) $row['ملاحظات'], 'en' => (string) $row['ملاحظات']] : null,
                        'price' => (float) ($row['السعر'] ?? 0),
                        'is_active' => true,
                    ]);
                    $existingAddons[$name] = $addon;
                    $stats['addons_created']++;
                }
            }

            if ($scope === 'addons') {
                return;
            }

            // ==========================================
            // D. Pieces & Service Links (Vendor + Branches)
            // ==========================================
            if (in_array($scope, ['pieces', 'full'], true) && $vendorId) {
                // Map piece code -> list of service codes
                $pieceToServicesMap = [];
                foreach ($servicePieceLinks as $link) {
                    $svcCode = (string) ($link['Service_ID'] ?? $link['service_id'] ?? '');
                    $pieceCode = (string) ($link['Piece_ID'] ?? $link['piece_id'] ?? '');
                    if ($svcCode !== '' && $pieceCode !== '') {
                        $pieceToServicesMap[$pieceCode][] = $svcCode;
                    }
                }

                $existingPieces = Piece::where('vendor_id', $vendorId)->get()
                    ->keyBy(fn ($p) => trim($p->getTranslation('name', 'ar')));

                $touchedPieceIds = [];
                // piece_id => [real service ids...], built while walking $piecesIn so the
                // branch loop below can create properly branch-scoped service_piece rows.
                $pieceRealServiceIds = [];

                foreach ($piecesIn as $row) {
                    $name = trim((string) ($row['اسم القطعة'] ?? $row['name'] ?? ''));
                    $pieceCode = (string) ($row['Piece_ID'] ?? $row['piece_id'] ?? '');

                    if ($name === '') {
                        continue;
                    }

                    $piece = null;
                    if (isset($existingPieces[$name])) {
                        $piece = $existingPieces[$name];
                        $touchedPieceIds[] = $piece->id;
                        $stats['pieces_skipped']++;
                    } else {
                        if ($dryRun) {
                            $stats['pieces_created']++;
                            $stats['piece_service_attached'] += count($pieceToServicesMap[$pieceCode] ?? []) * max(count($branchIds), 1);
                            $stats['branch_piece_attached'] += count($branchIds);
                            continue;
                        }

                        $piece = Piece::create([
                            'vendor_id' => $vendorId,
                            'name' => ['ar' => $name, 'en' => $name],
                            'is_active' => true,
                        ]);
                        $existingPieces[$name] = $piece;
                        $touchedPieceIds[] = $piece->id;
                        $stats['pieces_created']++;
                    }

                    $linkedSvcCodes = $pieceToServicesMap[$pieceCode] ?? [];
                    foreach ($linkedSvcCodes as $sc) {
                        $realSvcId = $serviceIdMap[$sc] ?? null;
                        if (is_int($realSvcId)) {
                            $pieceRealServiceIds[$piece->id][] = $realSvcId;
                        }
                    }
                }

                // Attach touched pieces to selected branches, and — critically — create
                // service_piece rows scoped to each of those branches. Every app-facing
                // endpoint (PiecesController, order pricing, etc.) reads a piece's services
                // filtered by a specific service_piece.branch_id; a branch-less row is
                // invisible to all of them even though the dashboard's unscoped
                // Piece::services() relationship shows it fine. Mirrors what the manual
                // "assign services to piece at branch" endpoint already does.
                if (! $dryRun && ! empty($branchIds)) {
                    foreach ($touchedPieceIds as $pieceId) {
                        $realSvcIds = array_unique($pieceRealServiceIds[$pieceId] ?? []);

                        foreach ($branchIds as $branchId) {
                            $exists = \DB::table('branch_piece')
                                ->where('branch_id', $branchId)
                                ->where('piece_id', $pieceId)
                                ->exists();

                            if ($exists) {
                                $stats['branch_piece_skipped']++;
                            } else {
                                $branch = Branch::find($branchId);
                                if ($branch) {
                                    $branch->pieces()->syncWithoutDetaching([
                                        $pieceId => ['is_active' => true],
                                    ]);
                                    $stats['branch_piece_attached']++;
                                }
                            }

                            foreach ($realSvcIds as $realSvcId) {
                                $branchServiceExists = \DB::table('branch_service')
                                    ->where('branch_id', $branchId)
                                    ->where('service_id', $realSvcId)
                                    ->exists();

                                if ($branchServiceExists) {
                                    \DB::table('branch_service')
                                        ->where('branch_id', $branchId)
                                        ->where('service_id', $realSvcId)
                                        ->update(['is_active' => true, 'updated_at' => now()]);
                                } else {
                                    \DB::table('branch_service')->insert([
                                        'branch_id' => $branchId,
                                        'service_id' => $realSvcId,
                                        'is_active' => true,
                                        'created_at' => now(),
                                        'updated_at' => now(),
                                    ]);
                                }

                                $alreadyAttached = \DB::table('service_piece')
                                    ->where('piece_id', $pieceId)
                                    ->where('service_id', $realSvcId)
                                    ->where('branch_id', $branchId)
                                    ->exists();

                                if (! $alreadyAttached) {
                                    \DB::table('service_piece')->insert([
                                        'piece_id' => $pieceId,
                                        'service_id' => $realSvcId,
                                        'branch_id' => $branchId,
                                        'price' => 0.0,
                                        'created_at' => now(),
                                        'updated_at' => now(),
                                    ]);
                                    $stats['piece_service_attached']++;
                                }
                            }
                        }
                    }
                } elseif (! $dryRun) {
                    // No branch selected (vendor-level "pieces only" import): keep a
                    // branch-less catalog record so the link survives until the admin
                    // assigns the piece to a branch — the app can't use it yet either way.
                    foreach ($touchedPieceIds as $pieceId) {
                        foreach (array_unique($pieceRealServiceIds[$pieceId] ?? []) as $realSvcId) {
                            $alreadyAttached = \DB::table('service_piece')
                                ->where('piece_id', $pieceId)
                                ->where('service_id', $realSvcId)
                                ->whereNull('branch_id')
                                ->exists();

                            if (! $alreadyAttached) {
                                \DB::table('service_piece')->insert([
                                    'piece_id' => $pieceId,
                                    'service_id' => $realSvcId,
                                    'price' => 0.0,
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);
                                $stats['piece_service_attached']++;
                            }
                        }
                    }
                }
            }
        };

        if ($dryRun) {
            $run();
        } else {
            \DB::transaction($run);
        }

        return $stats;
    }

    /**
     * Reads a sheet or returns empty array if sheet not found.
     */
    private function sheetRowsOrEmpty($spreadsheet, string $sheetName, array $possibleIdHeaders): array
    {
        $sheet = $spreadsheet->getSheetByName($sheetName);
        if (! $sheet) {
            return [];
        }

        $rows = $sheet->toArray(null, true, true, false);
        $headerRowIndex = null;
        $headers = [];

        foreach ($rows as $i => $row) {
            foreach ($possibleIdHeaders as $idHeader) {
                if (in_array($idHeader, $row, true)) {
                    $headerRowIndex = $i;
                    $headers = $row;
                    break 2;
                }
            }
        }

        if ($headerRowIndex === null) {
            return [];
        }

        $data = [];
        $rowCount = count($rows);
        for ($i = $headerRowIndex + 1; $i < $rowCount; $i++) {
            $row = $rows[$i];
            if ($row[0] === null || $row[0] === '') {
                continue;
            }
            $assoc = [];
            foreach ($headers as $colIdx => $headerName) {
                if ($headerName === null || $headerName === '') {
                    continue;
                }
                $assoc[$headerName] = $row[$colIdx] ?? null;
            }
            $data[] = $assoc;
        }

        return $data;
    }
}
