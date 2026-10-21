<?php

namespace Modules\Admin\Services;

use Modules\Branch\Models\Branch;
use Modules\Category\Models\Category;
use Modules\Piece\Models\Piece;
use Modules\Service\Models\Service;
use Modules\Service\Models\ServiceAddition;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Service for importing catalog data from the Master Catalog Excel. Supports
 * both the current "Developer Import v10" format (English sheet names,
 * separate _AR/_EN name columns, a Service_Price column, and an explicit
 * Section<->Service many-to-many sheet) and the older v6 format (Arabic sheet
 * names, a single combined name column) for any file still in that shape —
 * each sheet is looked up under its v10 name first, falling back to its v6
 * name.
 *
 * Scopes supported:
 * - 'categories' : Imports 01_Sections into global categories.
 * - 'services'   : Imports 02_Services into global services, and
 *                   03_Section_Service into the category_service pivot (a
 *                   service can legitimately belong to more than one
 *                   category — confirmed real data, not an import quirk).
 * - 'pieces'     : Imports 04_Pieces, 05_Service_Piece (with its per-pair
 *                   Service_Price as the seed price) and 07_Piece_AddOn into
 *                   vendor pieces, branch links, and branch-scoped
 *                   service_piece / service_addition_piece rows. Also
 *                   creates any referenced service/addon that doesn't
 *                   already exist, so a piece's links never silently end up
 *                   empty just because this scope was used on its own.
 * - 'addons'     : Imports 06_AddOns into vendor additional services
 *                   (ServiceAddition) — the sheet is a global name catalog,
 *                   but service_additions.vendor_id is required, so a
 *                   per-vendor copy is created/matched by name.
 * - 'full'       : Imports all available sheets in the workbook.
 */
class LaundryCatalogImportService
{
    private const SECTIONS_SHEET = '01_Sections';

    private const SECTIONS_SHEET_LEGACY = '01_الأقسام';

    private const SERVICES_SHEET = '02_Services';

    private const SERVICES_SHEET_LEGACY = '02_الخدمات';

    private const SECTION_SERVICE_SHEET = '03_Section_Service';

    private const PIECES_SHEET = '04_Pieces';

    private const PIECES_SHEET_LEGACY = '03_القطع';

    private const SERVICE_PIECE_LINKS_SHEET = '05_Service_Piece';

    private const SERVICE_PIECE_LINKS_SHEET_LEGACY = '04_ربط_الخدمة_بالقطعة';

    private const ADDONS_SHEET = '06_AddOns';

    private const ADDONS_SHEET_V6 = '05_الإضافات';

    private const ADDONS_SHEET_LEGACY = '04_الإضافية';

    private const PIECE_ADDON_LINKS_SHEET = '07_Piece_AddOn';

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
            'category_links_attached' => 0,
            'services_created' => 0,
            'services_skipped' => 0,
            'pieces_created' => 0,
            'pieces_skipped' => 0,
            'addons_created' => 0,
            'addons_skipped' => 0,
            'piece_service_attached' => 0,
            'piece_service_price_updated' => 0,
            'piece_addon_attached' => 0,
            'piece_addon_price_updated' => 0,
            'branch_piece_attached' => 0,
            'branch_piece_skipped' => 0,
        ];

        // 1. Sections/Categories
        $sections = in_array($scope, ['categories', 'services', 'pieces', 'full'], true)
            ? $this->readSheet($spreadsheet, [self::SECTIONS_SHEET, self::SECTIONS_SHEET_LEGACY], ['Section_ID', 'section_id'])
            : [];

        // 2. Services
        $servicesIn = in_array($scope, ['services', 'pieces', 'full'], true)
            ? $this->readSheet($spreadsheet, [self::SERVICES_SHEET, self::SERVICES_SHEET_LEGACY], ['Service_ID', 'service_id'])
            : [];

        // 3. Section<->Service many-to-many (v10 only; no v6 equivalent)
        $sectionServiceLinks = in_array($scope, ['services', 'pieces', 'full'], true)
            ? $this->readSheet($spreadsheet, [self::SECTION_SERVICE_SHEET], ['Section_ID', 'section_id'])
            : [];

        // 4. Pieces
        $piecesIn = in_array($scope, ['pieces', 'full'], true)
            ? $this->readSheet($spreadsheet, [self::PIECES_SHEET, self::PIECES_SHEET_LEGACY], ['Piece_ID', 'piece_id'])
            : [];

        // 5. Service<->Piece links (with per-pair price in v10)
        $servicePieceLinks = in_array($scope, ['pieces', 'full'], true)
            ? $this->readSheet($spreadsheet, [self::SERVICE_PIECE_LINKS_SHEET, self::SERVICE_PIECE_LINKS_SHEET_LEGACY], ['Service_ID', 'service_id'])
            : [];

        // 6. Addons (name catalog)
        $addonsIn = in_array($scope, ['addons', 'pieces', 'full'], true)
            ? $this->readSheet($spreadsheet, [self::ADDONS_SHEET, self::ADDONS_SHEET_V6, self::ADDONS_SHEET_LEGACY], ['AddOn_ID', 'addon_id'])
            : [];

        // 7. Piece<->AddOn suggested links (v10 only; no v6 equivalent)
        $pieceAddonLinks = in_array($scope, ['pieces', 'full'], true)
            ? $this->readSheet($spreadsheet, [self::PIECE_ADDON_LINKS_SHEET], ['Piece_ID', 'piece_id'])
            : [];

        $run = function () use (
            $sections,
            $servicesIn,
            $sectionServiceLinks,
            $piecesIn,
            $servicePieceLinks,
            $addonsIn,
            $pieceAddonLinks,
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
                $name = $this->bilingualName($row, 'section_name_AR', 'section_name_EN', ['اسم القسم', 'name']);
                $sectionCode = (string) ($row['Section_ID'] ?? $row['section_id'] ?? '');

                if ($name['ar'] === '') {
                    continue;
                }

                if (isset($existingCategories[$name['ar']])) {
                    $existingCat = $existingCategories[$name['ar']];
                    if ($sectionCode !== '') {
                        $categoryIdMap[$sectionCode] = $existingCat->id;
                    }
                    // Fix: if EN was stored as AR (old v6 import bug), update it
                    if (
                        ! $dryRun
                        && $name['en'] !== ''
                        && $name['en'] !== $name['ar']
                        && $existingCat->getTranslation('name', 'en') === $name['ar']
                    ) {
                        $existingCat->setTranslation('name', 'en', $name['en'])->save();
                        $stats['categories_updated'] = ($stats['categories_updated'] ?? 0) + 1;
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
                    'name' => ['ar' => $name['ar'], 'en' => $name['en']],
                    'order' => (int) ($row['ترتيب العرض'] ?? 0),
                    'is_active' => true,
                ]);

                if ($sectionCode !== '') {
                    $categoryIdMap[$sectionCode] = $cat->id;
                }
                $existingCategories[$name['ar']] = $cat;
                $stats['categories_created']++;
            }

            if ($scope === 'categories') {
                return;
            }

            // ==========================================
            // B. Services (Global) + category_service links
            // ==========================================

            // A service's categories now live in 03_Section_Service rather than inline
            // on the services sheet — build code -> [section codes...] first so the
            // first one can seed the still-required services.category_id below, and the
            // rest get recorded in category_service once every id is known.
            $sectionCodesByServiceCode = [];
            foreach ($sectionServiceLinks as $link) {
                $secCode = (string) ($link['Section_ID'] ?? $link['section_id'] ?? '');
                $svcCode = (string) ($link['Service_ID'] ?? $link['service_id'] ?? '');
                if ($secCode !== '' && $svcCode !== '') {
                    $sectionCodesByServiceCode[$svcCode][] = $secCode;
                }
            }

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
                $name = $this->bilingualName($row, 'service_name_AR', 'service_name_EN', ['اسم الخدمة', 'name']);
                $svcCode = (string) ($row['Service_ID'] ?? $row['service_id'] ?? '');

                if ($name['ar'] === '') {
                    continue;
                }

                if (isset($existingServices[$name['ar']])) {
                    $existingSvc = $existingServices[$name['ar']];
                    if ($svcCode !== '') {
                        $serviceIdMap[$svcCode] = $existingSvc->id;
                    }
                    // Fix: if EN was stored as AR (old v6 import bug), update it
                    if (
                        ! $dryRun
                        && $name['en'] !== ''
                        && $name['en'] !== $name['ar']
                        && $existingSvc->getTranslation('service_name', 'en') === $name['ar']
                    ) {
                        $existingSvc->setTranslation('service_name', 'en', $name['en'])->save();
                        $stats['services_updated'] = ($stats['services_updated'] ?? 0) + 1;
                    }
                    if ($canCreateServices) {
                        $stats['services_skipped']++;
                    }
                    continue;
                }

                if (! $canCreateServices) {
                    continue;
                }

                $firstSectionCode = $sectionCodesByServiceCode[$svcCode][0] ?? null;
                $catId = $firstSectionCode !== null ? ($categoryIdMap[$firstSectionCode] ?? null) : null;
                if (! is_int($catId)) {
                    // Legacy fallback: an inline Section_ID/القسم column directly on this row.
                    $legacySectionCode = (string) ($row['Section_ID'] ?? $row['section_id'] ?? '');
                    $catId = $categoryIdMap[$legacySectionCode] ?? null;
                }
                if (! is_int($catId)) {
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
                    'service_name' => ['ar' => $name['ar'], 'en' => $name['en']],
                    'category_id' => $catId,
                    'order' => (int) ($row['ترتيب الخدمة'] ?? 0),
                    'is_active' => true,
                ]);

                if ($svcCode !== '') {
                    $serviceIdMap[$svcCode] = $svc->id;
                }
                $existingServices[$name['ar']] = $svc;
                $stats['services_created']++;
            }

            // Now that every category and service id is resolved, record every
            // section a service is linked to (not just the first) in category_service.
            if (! $dryRun) {
                foreach ($sectionCodesByServiceCode as $svcCode => $secCodes) {
                    $svcId = $serviceIdMap[$svcCode] ?? null;
                    if (! is_int($svcId)) {
                        continue;
                    }
                    foreach (array_unique($secCodes) as $secCode) {
                        $catId = $categoryIdMap[$secCode] ?? null;
                        if (! is_int($catId)) {
                            continue;
                        }
                        $exists = \DB::table('category_service')
                            ->where('category_id', $catId)
                            ->where('service_id', $svcId)
                            ->exists();
                        if (! $exists) {
                            \DB::table('category_service')->insert([
                                'category_id' => $catId,
                                'service_id' => $svcId,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                            $stats['category_links_attached']++;
                        }
                    }
                }
            } else {
                $stats['category_links_attached'] += array_sum(array_map('count', $sectionCodesByServiceCode));
            }

            if ($scope === 'services') {
                return;
            }

            // ==========================================
            // C. Addons / Additional Services (Vendor-Scoped)
            // ==========================================
            // service_additions.vendor_id is required (no global addon catalog), so
            // 06_AddOns' catalog names are matched/created per vendor by name — same
            // as a plain vendor addon import, just with the AddOn_ID code also kept so
            // 07_Piece_AddOn below can resolve its links to the per-vendor row.
            $addonIdMap = [];
            if (in_array($scope, ['addons', 'pieces', 'full'], true) && $vendorId) {
                $existingAddons = ServiceAddition::where('vendor_id', $vendorId)->get()
                    ->keyBy(fn ($a) => trim($a->getTranslation('name', 'ar')));
                $canCreateAddons = in_array($scope, ['addons', 'full'], true);

                foreach ($addonsIn as $row) {
                    $name = $this->bilingualName($row, 'add_on_name_AR', 'add_on_name_EN', ['اسم الإضافة', 'اسم الخدمة الإضافية', 'name']);
                    $addonCode = (string) ($row['AddOn_ID'] ?? $row['addon_id'] ?? '');

                    if ($name['ar'] === '') {
                        continue;
                    }

                    if (isset($existingAddons[$name['ar']])) {
                        $existingAddon = $existingAddons[$name['ar']];
                        if ($addonCode !== '') {
                            $addonIdMap[$addonCode] = $existingAddon->id;
                        }
                        // Fix: if EN was stored as AR (old v6 import bug), update it
                        if (
                            ! $dryRun
                            && $name['en'] !== ''
                            && $name['en'] !== $name['ar']
                            && $existingAddon->getTranslation('name', 'en') === $name['ar']
                        ) {
                            $existingAddon->setTranslation('name', 'en', $name['en'])->save();
                            $stats['addons_updated'] = ($stats['addons_updated'] ?? 0) + 1;
                        }
                        if ($canCreateAddons) {
                            $stats['addons_skipped']++;
                        }
                        continue;
                    }

                    if (! $canCreateAddons) {
                        continue;
                    }

                    if ($dryRun) {
                        if ($addonCode !== '') {
                            $addonIdMap[$addonCode] = 'NEW:' . $addonCode;
                        }
                        $stats['addons_created']++;
                        continue;
                    }

                    $addon = ServiceAddition::create([
                        'vendor_id' => $vendorId,
                        'name' => ['ar' => $name['ar'], 'en' => $name['en']],
                        'description' => ! empty($row['ملاحظات']) ? ['ar' => (string) $row['ملاحظات'], 'en' => (string) $row['ملاحظات']] : null,
                        'price' => (float) ($row['السعر'] ?? 0),
                        'is_active' => true,
                    ]);
                    $existingAddons[$name['ar']] = $addon;
                    if ($addonCode !== '') {
                        $addonIdMap[$addonCode] = $addon->id;
                    }
                    $stats['addons_created']++;
                }
            }

            if ($scope === 'addons') {
                return;
            }

            // ==========================================
            // D. Pieces & Service/AddOn Links (Vendor + Branches)
            // ==========================================
            if (in_array($scope, ['pieces', 'full'], true) && $vendorId) {
                // Map piece code -> list of [service id/placeholder, price]
                $pieceToServicesMap = [];
                foreach ($servicePieceLinks as $link) {
                    $svcCode = (string) ($link['Service_ID'] ?? $link['service_id'] ?? '');
                    $pieceCode = (string) ($link['Piece_ID'] ?? $link['piece_id'] ?? '');
                    if ($svcCode === '' || $pieceCode === '') {
                        continue;
                    }
                    $price = (float) ($link['Service_Price'] ?? $link['service_price'] ?? 0);
                    $pieceToServicesMap[$pieceCode][] = ['code' => $svcCode, 'price' => $price];
                }

                // Map piece code -> category_id derived from Section_ID in 04_Pieces
                // (the "Section_ID" column links each piece to its section/department)
                $pieceToCategoryMap = [];
                foreach ($piecesIn as $row) {
                    $pCode = (string) ($row['Piece_ID'] ?? $row['piece_id'] ?? '');
                    $secCode = (string) ($row['Section_ID'] ?? $row['section_id'] ?? '');
                    if ($pCode !== '' && $secCode !== '' && isset($categoryIdMap[$secCode]) && is_int($categoryIdMap[$secCode])) {
                        $pieceToCategoryMap[$pCode] = $categoryIdMap[$secCode];
                    }
                }

                // Map piece code -> list of addon codes (no price on this sheet — the
                // addon's own catalog price, set in section C, is used as the seed).
                $pieceToAddonsMap = [];
                foreach ($pieceAddonLinks as $link) {
                    $pieceCode = (string) ($link['Piece_ID'] ?? $link['piece_id'] ?? '');
                    $addonCode = (string) ($link['AddOn_ID'] ?? $link['addon_id'] ?? '');
                    if ($pieceCode !== '' && $addonCode !== '') {
                        $pieceToAddonsMap[$pieceCode][] = $addonCode;
                    }
                }

                $existingPieces = Piece::where('vendor_id', $vendorId)->get()
                    ->keyBy(fn ($p) => trim($p->getTranslation('name', 'ar')));

                $touchedPieceIds = [];
                // piece_id => [['service_id' => int, 'price' => float], ...]
                $pieceRealServices = [];
                // piece_id => [['addon_id' => int, 'price' => float], ...]
                $pieceRealAddons = [];

                foreach ($piecesIn as $row) {
                    $name = $this->bilingualName($row, 'piece_name_AR', 'piece_name_EN', ['اسم القطعة', 'name']);
                    $pieceCode = (string) ($row['Piece_ID'] ?? $row['piece_id'] ?? '');
                    $pieceCategoryId = $pieceToCategoryMap[$pieceCode] ?? null;

                    if ($name['ar'] === '') {
                        continue;
                    }

                    $piece = null;
                    if (isset($existingPieces[$name['ar']])) {
                        $piece = $existingPieces[$name['ar']];
                        // Fix: if EN was stored as AR (old v6 import bug), update it
                        if (
                            ! $dryRun
                            && $name['en'] !== ''
                            && $name['en'] !== $name['ar']
                            && $piece->getTranslation('name', 'en') === $name['ar']
                        ) {
                            $piece->setTranslation('name', 'en', $name['en'])->save();
                            $stats['pieces_updated'] = ($stats['pieces_updated'] ?? 0) + 1;
                        }
                        // Update category_id if now known and not yet set
                        if (! $dryRun && $pieceCategoryId !== null && $piece->category_id === null) {
                            $piece->update(['category_id' => $pieceCategoryId]);
                            $stats['pieces_updated'] = ($stats['pieces_updated'] ?? 0) + 1;
                        }
                        $touchedPieceIds[] = $piece->id;
                        $stats['pieces_skipped']++;
                    } else {
                        if ($dryRun) {
                            $stats['pieces_created']++;
                            $stats['piece_service_attached'] += count($pieceToServicesMap[$pieceCode] ?? []) * max(count($branchIds), 1);
                            $stats['piece_addon_attached'] += count($pieceToAddonsMap[$pieceCode] ?? []) * max(count($branchIds), 1);
                            $stats['branch_piece_attached'] += count($branchIds);
                            continue;
                        }

                        $piece = Piece::create([
                            'vendor_id'   => $vendorId,
                            'category_id' => $pieceCategoryId,
                            'name'        => ['ar' => $name['ar'], 'en' => $name['en']],
                            'is_active'   => true,
                        ]);
                        $existingPieces[$name['ar']] = $piece;
                        $touchedPieceIds[] = $piece->id;
                        $stats['pieces_created']++;
                    }

                    foreach ($pieceToServicesMap[$pieceCode] ?? [] as $link) {
                        $realSvcId = $serviceIdMap[$link['code']] ?? null;
                        if (is_int($realSvcId)) {
                            $pieceRealServices[$piece->id][] = ['service_id' => $realSvcId, 'price' => $link['price']];
                        }
                    }

                    foreach ($pieceToAddonsMap[$pieceCode] ?? [] as $addonCode) {
                        $realAddonId = $addonIdMap[$addonCode] ?? null;
                        if (is_int($realAddonId)) {
                            $pieceRealAddons[$piece->id][] = $realAddonId;
                        }
                    }
                }

                // Attach touched pieces to selected branches, and — critically — create
                // service_piece / service_addition_piece rows scoped to each of those
                // branches. Every app-facing endpoint (PiecesController, order pricing,
                // etc.) reads a piece's services/addons filtered by a specific branch_id;
                // a branch-less row is invisible to all of them even though the
                // dashboard's unscoped relationships show it fine. Mirrors what the
                // manual "assign services to piece at branch" endpoint already does.
                if (! $dryRun && ! empty($branchIds)) {
                    foreach ($touchedPieceIds as $pieceId) {
                        $realServices = $pieceRealServices[$pieceId] ?? [];
                        $realAddonIds = array_unique($pieceRealAddons[$pieceId] ?? []);

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

                            foreach ($realServices as $svcLink) {
                                $realSvcId = $svcLink['service_id'];

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

                                $existingLink = \DB::table('service_piece')
                                    ->where('piece_id', $pieceId)
                                    ->where('service_id', $realSvcId)
                                    ->where('branch_id', $branchId)
                                    ->first();

                                if (! $existingLink) {
                                    \DB::table('service_piece')->insert([
                                        'piece_id' => $pieceId,
                                        'service_id' => $realSvcId,
                                        'branch_id' => $branchId,
                                        'price' => $svcLink['price'],
                                        'created_at' => now(),
                                        'updated_at' => now(),
                                    ]);
                                    $stats['piece_service_attached']++;
                                } elseif ((float) $existingLink->price !== $svcLink['price']) {
                                    // The sheet is authoritative on re-import: an earlier run
                                    // (e.g. before Service_Price support existed) may have left
                                    // this row at a stale/placeholder price like 0.
                                    \DB::table('service_piece')
                                        ->where('id', $existingLink->id)
                                        ->update(['price' => $svcLink['price'], 'updated_at' => now()]);
                                    $stats['piece_service_price_updated']++;
                                }
                            }

                            foreach ($realAddonIds as $realAddonId) {
                                $existingLink = \DB::table('service_addition_piece')
                                    ->where('piece_id', $pieceId)
                                    ->where('service_addition_id', $realAddonId)
                                    ->where('branch_id', $branchId)
                                    ->first();
                                $addonPrice = (float) (ServiceAddition::find($realAddonId)?->price ?? 0);

                                if (! $existingLink) {
                                    \DB::table('service_addition_piece')->insert([
                                        'piece_id' => $pieceId,
                                        'service_addition_id' => $realAddonId,
                                        'branch_id' => $branchId,
                                        'price' => $addonPrice,
                                        'created_at' => now(),
                                        'updated_at' => now(),
                                    ]);
                                    $stats['piece_addon_attached']++;
                                } elseif ((float) $existingLink->price !== $addonPrice) {
                                    \DB::table('service_addition_piece')
                                        ->where('id', $existingLink->id)
                                        ->update(['price' => $addonPrice, 'updated_at' => now()]);
                                    $stats['piece_addon_price_updated']++;
                                }
                            }
                        }
                    }
                } elseif (! $dryRun) {
                    // No branch selected (vendor-level "pieces only" import): keep
                    // branch-less catalog records so the links survive until the admin
                    // assigns the piece to a branch — the app can't use them yet either way.
                    foreach ($touchedPieceIds as $pieceId) {
                        foreach ($pieceRealServices[$pieceId] ?? [] as $svcLink) {
                            $realSvcId = $svcLink['service_id'];
                            $existingLink = \DB::table('service_piece')
                                ->where('piece_id', $pieceId)
                                ->where('service_id', $realSvcId)
                                ->whereNull('branch_id')
                                ->first();

                            if (! $existingLink) {
                                \DB::table('service_piece')->insert([
                                    'piece_id' => $pieceId,
                                    'service_id' => $realSvcId,
                                    'price' => $svcLink['price'],
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);
                                $stats['piece_service_attached']++;
                            } elseif ((float) $existingLink->price !== $svcLink['price']) {
                                \DB::table('service_piece')
                                    ->where('id', $existingLink->id)
                                    ->update(['price' => $svcLink['price'], 'updated_at' => now()]);
                                $stats['piece_service_price_updated']++;
                            }
                        }

                        foreach (array_unique($pieceRealAddons[$pieceId] ?? []) as $realAddonId) {
                            $existingLink = \DB::table('service_addition_piece')
                                ->where('piece_id', $pieceId)
                                ->where('service_addition_id', $realAddonId)
                                ->whereNull('branch_id')
                                ->first();
                            $addonPrice = (float) (ServiceAddition::find($realAddonId)?->price ?? 0);

                            if (! $existingLink) {
                                \DB::table('service_addition_piece')->insert([
                                    'piece_id' => $pieceId,
                                    'service_addition_id' => $realAddonId,
                                    'price' => $addonPrice,
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);
                                $stats['piece_addon_attached']++;
                            } elseif ((float) $existingLink->price !== $addonPrice) {
                                \DB::table('service_addition_piece')
                                    ->where('id', $existingLink->id)
                                    ->update(['price' => $addonPrice, 'updated_at' => now()]);
                                $stats['piece_addon_price_updated']++;
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
     * Resolve a bilingual name from a row: prefers the v10 separate _AR/_EN
     * columns, falling back to a single combined column (v6, or either
     * language missing) duplicated into both locales.
     *
     * @return array{ar: string, en: string}
     */
    private function bilingualName(array $row, string $arKey, string $enKey, array $fallbackKeys = []): array
    {
        $ar = trim((string) ($row[$arKey] ?? ''));
        $en = trim((string) ($row[$enKey] ?? ''));

        if ($ar === '' && $en === '') {
            foreach ($fallbackKeys as $key) {
                $value = trim((string) ($row[$key] ?? ''));
                if ($value !== '') {
                    $ar = $en = $value;
                    break;
                }
            }
        }

        if ($ar === '') {
            $ar = $en;
        }
        if ($en === '') {
            $en = $ar;
        }

        return ['ar' => $ar, 'en' => $en];
    }

    /**
     * Reads the first sheet found among $sheetNames (tried in order), or
     * returns empty if none of them exist in the workbook.
     */
    private function readSheet($spreadsheet, array $sheetNames, array $possibleIdHeaders): array
    {
        foreach ($sheetNames as $sheetName) {
            $rows = $this->sheetRowsOrEmpty($spreadsheet, $sheetName, $possibleIdHeaders);
            if (! empty($rows)) {
                return $rows;
            }
        }

        return [];
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
