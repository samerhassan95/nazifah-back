<?php

namespace Modules\Admin\Services;

use Modules\Category\Models\Category;
use Modules\Service\Models\Service;
use Modules\Service\Models\ServiceAddition;
use Modules\Piece\Models\Piece;
use Modules\Branch\Models\Branch;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Imports the "Nathefah Saudi Full Service Catalog" Excel template:
 *   01_الأقسام          section_id | اسم القسم | ترتيب العرض | الحالة
 *   02_الخدمات          service_id | section_id | القسم | اسم الخدمة | ترتيب الخدمة | عدد القطع | الحالة
 *   03_القطع            piece_id | service_id | section_id | القسم | الخدمة | اسم القطعة | السعر | حالة السعر | الحالة | مصدر الشريحة
 *   04_الإضافية         addon_id | اسم الخدمة الإضافية | السعر | طريقة الاحتساب | ملاحظات | الحالة
 *   05_ربط_الإضافية     service_addon_id | service_id | اسم الخدمة | addon_id | الخدمة الإضافية | الحالة
 *
 * Categories and services are global (shared by every laundry) - the admin adds them once and
 * each vendor then picks from that shared catalog, it's never duplicated per vendor. Pieces and
 * additional services ARE vendor-scoped and only make sense together with the vendor/branch they
 * belong to, so they only import under scope=full.
 *
 * Scopes:
 *   'categories' - only sheet 01. No vendor_id/branch_ids needed.
 *   'services'   - only sheet 02, matched against categories that already exist (by Arabic name);
 *                  does not create new categories. No vendor_id/branch_ids needed.
 *   'full'       - everything (sheets 01-05) plus linking pieces to the given branches.
 *                  Requires vendor_id and branch_ids.
 *
 * A name match (global for categories/services, per-vendor for pieces/additional services) skips
 * re-creating that row. A piece name that repeats under several services in the file (e.g. "بنطلون"
 * under both "ملابس يومية" and "كوي بالبخار") is ONE piece attached to several services, each with
 * its own price - not several duplicate pieces.
 */
class LaundryCatalogImportService
{
    private const SECTIONS_SHEET = '01_الأقسام';

    private const SERVICES_SHEET = '02_الخدمات';

    private const PIECES_SHEET = '03_القطع';

    private const ADDONS_SHEET = '04_الإضافية';

    private const ADDON_LINKS_SHEET = '05_ربط_الإضافية';

    /**
     * @param  int[]  $branchIds
     */
    public function import(
        string $filePath,
        ?int $vendorId,
        array $branchIds,
        bool $dryRun = true,
        string $scope = 'full'
    ): array {
        $spreadsheet = IOFactory::load($filePath);

        $sections = $scope === 'categories' || $scope === 'full'
            ? $this->sheetRows($spreadsheet, self::SECTIONS_SHEET, 'section_id')
            : [];
        $servicesIn = $scope === 'services' || $scope === 'full'
            ? $this->sheetRows($spreadsheet, self::SERVICES_SHEET, 'service_id')
            : [];
        $piecesIn = $scope === 'full'
            ? $this->sheetRows($spreadsheet, self::PIECES_SHEET, 'piece_id')
            : [];
        $addonsIn = $scope === 'full'
            ? $this->sheetRows($spreadsheet, self::ADDONS_SHEET, 'addon_id')
            : [];
        $addonLinksIn = $scope === 'full'
            ? $this->sheetRows($spreadsheet, self::ADDON_LINKS_SHEET, 'service_addon_id')
            : [];

        $stats = [
            'categories_created' => 0, 'categories_skipped' => 0,
            'services_created' => 0, 'services_skipped' => 0,
            'pieces_created' => 0, 'pieces_skipped' => 0,
            'addons_created' => 0, 'addons_skipped' => 0,
            'piece_service_attached' => 0,
            'addon_service_links_attached' => 0, 'addon_service_links_skipped' => 0,
            'piece_addon_branch_attached' => 0,
            'branch_piece_attached' => 0, 'branch_piece_skipped' => 0,
        ];

        $run = function () use (
            $sections, $servicesIn, $piecesIn, $addonsIn, $addonLinksIn,
            $vendorId, $branchIds, &$stats, $dryRun, $scope
        ) {
            // ---- 1. Categories (global). Created only under scope categories/full. ----
            $existingCategories = Category::all()->keyBy(fn ($c) => trim($c->getTranslation('name', 'ar')));
            $categoryIdMap = [];
            $canCreateCategories = $scope === 'categories' || $scope === 'full';

            foreach ($sections as $row) {
                $name = trim((string) $row['اسم القسم']);
                if ($name === '') {
                    continue;
                }
                if (isset($existingCategories[$name])) {
                    $categoryIdMap[$row['section_id']] = $existingCategories[$name]->id;
                    $stats['categories_skipped']++;

                    continue;
                }

                if (! $canCreateCategories) {
                    continue;
                }

                if ($dryRun) {
                    $categoryIdMap[$row['section_id']] = 'NEW:'.$row['section_id'];
                    $stats['categories_created']++;

                    continue;
                }

                $cat = Category::create([
                    'name' => ['ar' => $name, 'en' => $name],
                    'order' => (int) ($row['ترتيب العرض'] ?? 0),
                    'is_active' => true,
                ]);
                $categoryIdMap[$row['section_id']] = $cat->id;
                $stats['categories_created']++;
            }

            // ---- 2. Services (global). Category is matched by name against EXISTING categories
            // only (never created here) when scope is 'services' - categories are that tab's job. ----
            $existingServices = Service::all()->keyBy(fn ($s) => trim($s->getTranslation('service_name', 'ar')));
            $serviceIdMap = [];

            foreach ($servicesIn as $row) {
                $name = trim((string) $row['اسم الخدمة']);
                if ($name === '') {
                    continue;
                }
                if (isset($existingServices[$name])) {
                    $serviceIdMap[$row['service_id']] = $existingServices[$name]->id;
                    $stats['services_skipped']++;

                    continue;
                }

                $catId = $categoryIdMap[$row['section_id']] ?? null;
                if (! is_int($catId) && $scope === 'services') {
                    // scope=services never creates categories; resolve purely from what already exists
                    $sectionName = trim((string) ($row['القسم'] ?? ''));
                    $catId = $existingCategories[$sectionName]->id ?? null;
                }

                if ($dryRun) {
                    $serviceIdMap[$row['service_id']] = 'NEW:'.$row['service_id'];
                    $stats['services_created']++;

                    continue;
                }

                $svc = Service::create([
                    'service_name' => ['ar' => $name, 'en' => $name],
                    'category_id' => is_int($catId) ? $catId : null,
                    'order' => (int) ($row['ترتيب الخدمة'] ?? 0),
                    'is_active' => true,
                ]);
                $serviceIdMap[$row['service_id']] = $svc->id;
                $stats['services_created']++;
            }

            if ($scope !== 'full') {
                return;
            }

            // ---- 3. Addons / additional services (vendor-scoped) ----
            $existingAddons = ServiceAddition::where('vendor_id', $vendorId)->get()
                ->keyBy(fn ($a) => trim($a->getTranslation('name', 'ar')));
            $addonIdMap = [];
            $newAddonIds = [];
            $addonPriceById = [];

            foreach ($addonsIn as $row) {
                $addonPriceById[$row['addon_id']] = (float) ($row['السعر'] ?? 0);
                $name = trim((string) $row['اسم الخدمة الإضافية']);
                if ($name === '') {
                    continue;
                }
                if (isset($existingAddons[$name])) {
                    $addonIdMap[$row['addon_id']] = $existingAddons[$name]->id;
                    $stats['addons_skipped']++;

                    continue;
                }

                if ($dryRun) {
                    $addonIdMap[$row['addon_id']] = 'NEW:'.$row['addon_id'];
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
                $addonIdMap[$row['addon_id']] = $addon->id;
                $newAddonIds[] = $addon->id;
                $stats['addons_created']++;
            }

            // ---- 4. Pieces (vendor-scoped), deduplicated by name ----
            $pieceGroups = [];
            foreach ($piecesIn as $row) {
                $name = trim((string) $row['اسم القطعة']);
                if ($name === '') {
                    continue;
                }
                $pieceGroups[$name]['rows'][] = [
                    'service_id' => $row['service_id'],
                    'price' => $row['السعر'] !== null && $row['السعر'] !== '' ? (float) $row['السعر'] : 0.0,
                ];
            }

            $serviceAddonMap = [];
            foreach ($addonLinksIn as $link) {
                if (empty($link['service_id']) || empty($link['addon_id'])) {
                    continue;
                }
                $serviceAddonMap[$link['service_id']][] = $link['addon_id'];
            }

            $existingPieces = Piece::where('vendor_id', $vendorId)->get()
                ->keyBy(fn ($p) => trim($p->getTranslation('name', 'ar')));

            $touchedPieceIds = []; // pieces to attach to branch_piece for every branch, whether new or matched

            foreach ($pieceGroups as $name => $group) {
                if (isset($existingPieces[$name])) {
                    $touchedPieceIds[] = $existingPieces[$name]->id;
                    $stats['pieces_skipped']++;

                    continue;
                }

                $uniqueServiceRows = [];
                foreach ($group['rows'] as $r) {
                    $uniqueServiceRows[$r['service_id']] = $r;
                }

                if ($dryRun) {
                    $stats['pieces_created']++;
                    $addonSet = [];
                    foreach (array_keys($uniqueServiceRows) as $fileServiceId) {
                        foreach (($serviceAddonMap[$fileServiceId] ?? []) as $a) {
                            $addonSet[$a] = true;
                        }
                    }
                    $stats['piece_service_attached'] += count($uniqueServiceRows);
                    $stats['piece_addon_branch_attached'] += count($addonSet) * count($branchIds);
                    $stats['branch_piece_attached'] += count($branchIds);

                    continue;
                }

                $piece = Piece::create([
                    'vendor_id' => $vendorId,
                    'name' => ['ar' => $name, 'en' => $name],
                    'is_active' => true,
                ]);
                $touchedPieceIds[] = $piece->id;
                $stats['pieces_created']++;

                $addonSet = [];
                foreach ($uniqueServiceRows as $fileServiceId => $r) {
                    $realServiceId = $serviceIdMap[$fileServiceId] ?? null;
                    if (is_int($realServiceId)) {
                        $piece->services()->attach($realServiceId, ['price' => $r['price']]);
                        $stats['piece_service_attached']++;
                    }
                    foreach (($serviceAddonMap[$fileServiceId] ?? []) as $a) {
                        $addonSet[$a] = true;
                    }
                }

                foreach (array_keys($addonSet) as $fileAddonId) {
                    $realAddonId = $addonIdMap[$fileAddonId] ?? null;
                    if (! is_int($realAddonId)) {
                        continue;
                    }
                    $addonPrice = $addonPriceById[$fileAddonId] ?? 0.0;
                    foreach ($branchIds as $branchId) {
                        $piece->additionalServices()->attach($realAddonId, [
                            'branch_id' => $branchId,
                            'price' => $addonPrice,
                        ]);
                        $stats['piece_addon_branch_attached']++;
                    }
                }
            }

            // ---- 5. Addon <-> Service general links (service_service_addition), only for addons we created ----
            foreach ($addonLinksIn as $link) {
                if (empty($link['service_id']) || empty($link['addon_id'])) {
                    continue;
                }
                $realAddonId = $addonIdMap[$link['addon_id']] ?? null;
                $realServiceId = $serviceIdMap[$link['service_id']] ?? null;

                if (! is_int($realAddonId) || ! is_int($realServiceId)) {
                    if ($dryRun) {
                        $stats['addon_service_links_attached']++;
                    }

                    continue;
                }

                if (! in_array($realAddonId, $newAddonIds, true)) {
                    $stats['addon_service_links_skipped']++;

                    continue;
                }

                $already = \DB::table('service_service_addition')
                    ->where('service_addition_id', $realAddonId)
                    ->where('service_id', $realServiceId)
                    ->exists();

                if ($already) {
                    $stats['addon_service_links_skipped']++;

                    continue;
                }

                if ($dryRun) {
                    $stats['addon_service_links_attached']++;

                    continue;
                }

                \DB::table('service_service_addition')->insert([
                    'service_addition_id' => $realAddonId,
                    'service_id' => $realServiceId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $stats['addon_service_links_attached']++;
            }

            // ---- 6. Attach every touched piece (new or matched) to every requested branch ----
            if (! $dryRun) {
                foreach ($touchedPieceIds as $pieceId) {
                    foreach ($branchIds as $branchId) {
                        $exists = \DB::table('branch_piece')
                            ->where('branch_id', $branchId)
                            ->where('piece_id', $pieceId)
                            ->exists();

                        if ($exists) {
                            $stats['branch_piece_skipped']++;

                            continue;
                        }

                        $branch = Branch::find($branchId);
                        if (! $branch) {
                            continue;
                        }
                        $branch->pieces()->syncWithoutDetaching([
                            $pieceId => ['is_active' => true],
                        ]);
                        $stats['branch_piece_attached']++;
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
     * Reads a sheet into an array of associative rows, locating the header row by searching
     * for $idColumnHeader rather than assuming a fixed row number (tolerates an extra title
     * row or blank spacer rows, as the reference template has).
     */
    private function sheetRows($spreadsheet, string $sheetName, string $idColumnHeader): array
    {
        $sheet = $spreadsheet->getSheetByName($sheetName);
        if (! $sheet) {
            throw new \RuntimeException("Required sheet not found: {$sheetName}");
        }

        $rows = $sheet->toArray(null, true, true, false);

        $headerRowIndex = null;
        $headers = [];
        foreach ($rows as $i => $row) {
            if (in_array($idColumnHeader, $row, true)) {
                $headerRowIndex = $i;
                $headers = $row;
                break;
            }
        }

        if ($headerRowIndex === null) {
            throw new \RuntimeException("Header row with '{$idColumnHeader}' not found in sheet {$sheetName}");
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
