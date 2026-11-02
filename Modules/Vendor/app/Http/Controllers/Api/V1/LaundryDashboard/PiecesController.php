<?php

namespace Modules\Vendor\Http\Controllers\Api\V1\LaundryDashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\Admin\Enums\IconType;
use Modules\Piece\Models\Piece;
use Modules\Vendor\Support\LaundryDashboardResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Laundry Dashboard → Operations Management → Pieces
 */
class PiecesController extends Controller
{
    use LaundryDashboardResponse;

    /** GET /pieces */
    public function index(Request $request): JsonResponse
    {
        $pieces = Piece::where('vendor_id', $this->vendorId())
            ->orderBy('id')
            ->get()
            ->map(fn (Piece $p) => $this->format($p));

        return $this->ok($pieces, 'تم جلب القطع بنجاح', 'Pieces retrieved successfully');
    }

    /** POST /pieces */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), $this->rules());
        if ($validator->fails()) {
            return $this->invalid($validator->errors());
        }

        $piece = Piece::create([
            'vendor_id' => $this->vendorId(),
            'name' => ['ar' => $request->input('name.ar'), 'en' => $request->input('name.en')],
            'icon_id' => $request->icon_id,
            'is_active' => true,
        ]);

        return $this->ok($this->format($piece->fresh()), 'تم إضافة القطعة بنجاح', 'Piece created successfully', 201);
    }

    /** PUT /pieces/{id}  (POST + _method=PUT also works) */
    public function update(Request $request, int $pieceId): JsonResponse
    {
        $piece = Piece::where('id', $pieceId)->where('vendor_id', $this->vendorId())->first();
        if (! $piece) {
            return $this->notFound('القطعة غير موجودة', 'Piece not found');
        }

        $validator = Validator::make($request->all(), $this->rules());
        if ($validator->fails()) {
            return $this->invalid($validator->errors());
        }

        $piece->update([
            'name' => ['ar' => $request->input('name.ar'), 'en' => $request->input('name.en')],
            'icon_id' => $request->icon_id,
        ]);

        return $this->ok($this->format($piece->fresh()), 'تم تحديث القطعة بنجاح', 'Piece updated successfully');
    }

    /**
     * POST /pieces/{id}/toggle-status
     * Optional body: { "is_active": true|false }
     */
    public function toggleStatus(Request $request, int $pieceId): JsonResponse
    {
        $piece = Piece::where('id', $pieceId)->where('vendor_id', $this->vendorId())->first();
        if (! $piece) {
            return $this->notFound('القطعة غير موجودة', 'Piece not found');
        }

        $piece->update([
            'is_active' => $request->has('is_active') ? $request->boolean('is_active') : ! $piece->is_active,
        ]);

        return $this->ok($this->format($piece->fresh()), 'تم تحديث حالة القطعة بنجاح', 'Piece status updated successfully');
    }

    /**
     * POST /pieces/import/check   (multipart: file)
     * Dry run — nothing is saved.
     */
    public function importCheck(Request $request): JsonResponse
    {
        return $this->runImport($request, false);
    }

    /**
     * POST /pieces/import   (multipart: file)
     */
    public function import(Request $request): JsonResponse
    {
        return $this->runImport($request, true);
    }

    private function rules(): array
    {
        return [
            'icon_id' => ['required', 'integer', 'exists:icons,id'],
            'name.ar' => ['required', 'string', 'max:255'],
            'name.en' => ['required', 'string', 'max:255'],
        ];
    }

    private function format(Piece $piece): array
    {
        $piece->loadMissing('iconRelation');

        return [
            'id' => $piece->id,
            'name' => $this->bilingual($piece->getTranslations('name')),
            'icon_id' => $piece->icon_id,
            'icon' => $piece->iconRelation?->full_path ?: null,
            'is_active' => (bool) $piece->is_active,
        ];
    }

    /**
     * Expected sheet (first sheet, row 1 = header): name_ar | name_en | icon_id (optional).
     * Accepts .xlsx / .xls / .csv.
     *
     * added_pieces        = rows that do not exist yet (matched by ar OR en name, per laundry; duplicates inside the file count once)
     * skipped_pieces      = rows that already exist
     * piece_service_links = always 0 — service prices are set per branch, so links are not created by this import
     */
    private function runImport(Request $request, bool $persist): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:5120'],
        ]);
        if ($validator->fails()) {
            return $this->invalid($validator->errors());
        }

        try {
            $sheet = IOFactory::load($request->file('file')->getRealPath())->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, false);
        } catch (\Throwable $e) {
            return $this->invalid(['file' => ['الملف غير صالح / Invalid file']]);
        }

        $header = array_map(fn ($h) => strtolower(trim((string) $h)), array_shift($rows) ?? []);
        $arCol = array_search('name_ar', $header, true);
        $enCol = array_search('name_en', $header, true);
        $iconCol = array_search('icon_id', $header, true);

        if ($arCol === false || $enCol === false) {
            return $this->invalid(['file' => ['الأعمدة المطلوبة: name_ar, name_en / Required columns: name_ar, name_en']]);
        }

        $vendorId = $this->vendorId();
        $existing = Piece::where('vendor_id', $vendorId)->get()->flatMap(
            fn (Piece $p) => collect($p->getTranslations('name'))->map(fn ($n) => mb_strtolower(trim((string) $n)))
        )->filter()->flip();

        $validIcons = $this->validPieceIconIds($rows, $iconCol);

        $toCreate = [];
        $skipped = 0;
        $errors = [];

        foreach ($rows as $i => $row) {
            $ar = trim((string) ($row[$arCol] ?? ''));
            $en = trim((string) ($row[$enCol] ?? ''));

            if ($ar === '' && $en === '') {
                continue; // blank line
            }
            if ($ar === '' || $en === '') {
                $errors[] = 'السطر '.($i + 2).': الاسم بالعربي والإنجليزي مطلوبان / Row '.($i + 2).': both names are required';

                continue;
            }

            $arKey = mb_strtolower($ar);
            $enKey = mb_strtolower($en);

            if ($existing->has($arKey) || $existing->has($enKey)) {
                $skipped++;

                continue;
            }

            $iconId = null;
            if ($iconCol !== false && ($row[$iconCol] ?? '') !== '') {
                $iconId = (int) $row[$iconCol];
                if (! in_array($iconId, $validIcons, true)) {
                    $errors[] = 'السطر '.($i + 2).': icon_id غير موجود / Row '.($i + 2).': icon_id does not exist';

                    continue;
                }
            }

            $existing->put($arKey, true)->put($enKey, true);
            $toCreate[] = ['name' => ['ar' => $ar, 'en' => $en], 'icon_id' => $iconId];
        }

        if ($errors) {
            return $this->fail('الملف يحتوي على أخطاء', 'The file contains invalid rows', 422, ['file' => $errors]);
        }

        if ($persist && $toCreate) {
            DB::transaction(function () use ($toCreate, $vendorId) {
                foreach ($toCreate as $row) {
                    Piece::create($row + ['vendor_id' => $vendorId, 'is_active' => true]);
                }
            });
        }

        return $this->ok(
            [
                'added_pieces' => count($toCreate),
                'skipped_pieces' => $skipped,
                'piece_service_links' => 0,
            ],
            $persist ? 'تم استيراد القطع بنجاح' : 'تم فحص الملف بنجاح',
            $persist ? 'Pieces imported successfully' : 'File checked successfully'
        );
    }

    /**
     * Ids of existing piece-type icons, restricted to ids referenced by the file.
     */
    private function validPieceIconIds(array $rows, int|false $col): array
    {
        if ($col === false) {
            return [];
        }

        $ids = collect($rows)->pluck($col)
            ->filter(fn ($v) => $v !== null && $v !== '')
            ->map(fn ($v) => (int) $v)
            ->unique()
            ->values();

        return \Modules\Admin\Models\Icon::whereIn('id', $ids)
            ->where('type', IconType::PIECE->value)
            ->pluck('id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }
}
