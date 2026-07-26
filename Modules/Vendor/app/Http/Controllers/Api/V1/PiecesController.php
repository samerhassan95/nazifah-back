<?php

namespace Modules\Vendor\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\UploadFilesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\Branch\Models\Branch;
use Modules\Piece\Models\Piece;
use Modules\Piece\Support\PieceBranchOffering;
use Modules\Piece\Support\PiecePricingFormatter;
use Modules\Service\Models\Service;
use Modules\Vendor\Models\Vendor;

class PiecesController extends Controller
{
    protected $uploadFilesService;

    public function __construct(UploadFilesService $uploadFilesService)
    {
        $this->uploadFilesService = $uploadFilesService;
    }

    public function index(Request $request): JsonResponse
    {
        $employee = $request->user();
        $vendorId = $employee->vendor_id;
        $lang = app()->getLocale();
        $branchId = $request->query('branch_id') ? (int) $request->query('branch_id') : null;

        $query = Piece::where('vendor_id', $vendorId)
            ->with('iconRelation');

        if ($branchId) {
            $branch = Branch::where('id', $branchId)->where('vendor_id', $vendorId)->first();
            if (! $branch) {
                return notFoundResponse(__('branch.branch_not_found_or_not_yours'));
            }

            $query->whereHas('branches', function ($q) use ($branchId) {
                $q->where('branches.id', $branchId);
            });
            $query->with([
                'services' => function ($q) use ($branchId) {
                    $q->where('services.is_active', true)
                        ->where('service_piece.branch_id', $branchId);
                },
            ]);
        }

        $pieces = $query->get();

        $branchPivotMap = $branchId
            ? PieceBranchOffering::pivotMapForPieces($branchId, $pieces->pluck('id')->map(fn ($id) => (int) $id)->all())
            : [];

        $additionalByPiece = $branchId
            ? PiecePricingFormatter::additionalServicesMapForPieces($pieces, $branchId, $lang)
            : [];

        $pieces = $pieces->map(function ($piece) use ($branchId, $lang, $additionalByPiece, $branchPivotMap) {
            $precomputed = $branchId ? ($additionalByPiece[(int) $piece->id] ?? []) : null;
            $pivotRow = $branchId ? ($branchPivotMap[(int) $piece->id] ?? null) : null;

            return $this->formatVendorPieceResponse($piece, $branchId, $lang, $precomputed, $pivotRow);
        });

        return successResponse($pieces, __('piece.pieces_retrieved'));
    }

    /**
     * Vendor catalog pieces not yet assigned to this branch (for add-to-branch dropdowns).
     * GET /api/v1/vendor/branches/{branchId}/available-pieces
     */
    public function availableForBranchById(Request $request, $branchId): JsonResponse
    {
        return $this->respondWithPiecesAvailableForBranch($request, (int) $branchId);
    }

    /**
     * Add piece (catalog only: name + icon).
     * Do not send branch_id or services here — use
     * POST /api/v1/vendor/branches/{branchId}/assign-service-additions
     * to attach the piece to a branch and set service_piece prices (additions use catalog price).
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), array_merge([
            'name' => ['required', 'array'],
            'name.ar' => ['required', 'string', 'max:255'],
            'name.en' => ['required', 'string', 'max:255'],
            'icon_id' => ['nullable', 'integer', 'exists:icons,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ], $this->pieceServicesValidationRules()));

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        if ($request->filled('services') && ! $request->filled('branch_id')) {
            return validationErrorResponse(['branch_id' => ['branch_id is required when services are provided.']]);
        }

        $employee = $request->user();
        $vendorId = $employee->vendor_id;
        $lang = app()->getLocale();

        $piece = Piece::create([
            'vendor_id' => $vendorId,
            'name' => [
                'ar' => $request->name['ar'],
                'en' => $request->name['en'],
            ],
            'icon_id' => $request->icon_id,
        ]);

        $branchId = $request->filled('branch_id') ? (int) $request->branch_id : null;

        if ($branchId && $request->filled('services')) {
            $branch = Branch::where('id', $branchId)->where('vendor_id', $vendorId)->first();
            if (! $branch) {
                return notFoundResponse(__('branch.branch_not_found_or_not_yours'));
            }
            $this->syncServicesToPieceAtBranch($piece, $branch, $request->services, $lang);
            flushCacheTags(["branch_{$branchId}", 'services', 'pieces', 'branches']);
        } elseif ($branchId) {
            $branch = Branch::where('id', $branchId)->where('vendor_id', $vendorId)->first();
            if ($branch && ! $branch->pieces()->where('pieces.id', $piece->id)->exists()) {
                $branch->pieces()->attach($piece->id);
                // cache invalidation: piece attached to branch
                flushCacheTags(["branch_{$branchId}", 'pieces', 'branches']);
            }
        }

        $piece->load('iconRelation');
        if ($branchId) {
            $piece->load(['services' => function ($q) use ($branchId) {
                $q->where('services.is_active', true)
                    ->where('service_piece.branch_id', $branchId);
            }]);
        }

        return successResponse(
            $this->formatVendorPieceResponse($piece, $branchId, $lang),
            __('piece.piece_created'),
            201
        );
    }

    /**
     * Update piece
     */
    public function update(Request $request, $pieceId): JsonResponse
    {
        $validator = Validator::make($request->all(), array_merge([
            'name' => ['sometimes', 'array'],
            'name.ar' => ['sometimes', 'string', 'max:255'],
            'name.en' => ['sometimes', 'string', 'max:255'],
            'icon_id' => ['nullable', 'integer', 'exists:icons,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ], $this->pieceServicesValidationRules()));

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        if ($request->filled('services') && ! $request->filled('branch_id')) {
            return validationErrorResponse(['branch_id' => ['branch_id is required when services are provided.']]);
        }

        $employee = $request->user();
        $vendorId = $employee->vendor_id;
        $lang = app()->getLocale();

        $piece = Piece::where('vendor_id', $vendorId)->find($pieceId);

        if (! $piece) {
            return notFoundResponse(__('piece.piece_not_found'));
        }

        $data = [];
        if ($request->has('name') && is_array($request->name)) {
            $data['name'] = [
                'ar' => $request->name['ar'] ?? $piece->getTranslation('name', 'ar'),
                'en' => $request->name['en'] ?? $piece->getTranslation('name', 'en'),
            ];
        }

        if ($request->has('icon_id')) {
            $data['icon_id'] = $request->icon_id;
        }

        if ($request->has('is_active')) {
            $data['is_active'] = $request->boolean('is_active');
        }

        $branchId = $request->filled('branch_id') ? (int) $request->branch_id : null;

        if ($branchId && $request->has('name') && is_array($request->name)) {
            $branch = Branch::where('id', $branchId)->where('vendor_id', $vendorId)->first();
            if (! $branch) {
                return notFoundResponse(__('branch.branch_not_found_or_not_yours'));
            }
            if (! $branch->pieces()->where('pieces.id', $piece->id)->exists()) {
                $branch->pieces()->attach($piece->id);
            }
            PieceBranchOffering::upsert($branchId, $piece, $request->name);
            unset($data['name']);
        }

        if (! empty($data)) {
            $piece->update($data);
        }

        if ($branchId && $request->has('services')) {
            $branch = Branch::where('id', $branchId)->where('vendor_id', $vendorId)->first();
            if (! $branch) {
                return notFoundResponse(__('branch.branch_not_found_or_not_yours'));
            }
            $this->syncServicesToPieceAtBranch($piece, $branch, $request->services ?? [], $lang);
            flushCacheTags(["branch_{$branchId}", 'services', 'pieces', 'branches']);
        }

        $piece->load('iconRelation');
        if ($branchId) {
            $piece->load(['services' => function ($q) use ($branchId) {
                $q->where('services.is_active', true)
                    ->where('service_piece.branch_id', $branchId);
            }]);
        }

        $pivotRow = $branchId ? PieceBranchOffering::find($branchId, (int) $piece->id) : null;
        $response = $this->formatVendorPieceResponse($piece, $branchId, $lang, null, $pivotRow);
        if (! $branchId) {
            $response['name_ar'] = $piece->getTranslation('name', 'ar');
            $response['name_en'] = $piece->getTranslation('name', 'en');
        }
        $response['updated_at'] = $piece->updated_at->toISOString();

        return successResponse($response, __('piece.piece_updated'));
    }

    /**
     * Get piece details
     */
    public function show(Request $request, $pieceId): JsonResponse
    {
        $employee = $request->user();
        $vendorId = $employee->vendor_id;
        $branchId = $request->query('branch_id') ? (int) $request->query('branch_id') : null;
        $lang = app()->getLocale();

        $pieceQuery = Piece::where('vendor_id', $vendorId)->with('iconRelation');

        if ($branchId) {
            $pieceQuery->with([
                'services' => function ($q) use ($branchId) {
                    $q->where('services.is_active', true)
                        ->where('service_piece.branch_id', $branchId);
                },
            ]);
        }

        $piece = $pieceQuery->find($pieceId);

        if (! $piece) {
            return notFoundResponse(__('piece.piece_not_found'));
        }

        $pivotRow = $branchId ? PieceBranchOffering::find($branchId, (int) $piece->id) : null;
        $data = $this->formatVendorPieceResponse($piece, $branchId, $lang, null, $pivotRow);
        if (! $branchId) {
            $data['name'] = [
                'ar' => $piece->getTranslation('name', 'ar'),
                'en' => $piece->getTranslation('name', 'en'),
            ];
        }
        $data['vendor_id'] = $piece->vendor_id;
        $data['created_at'] = $piece->created_at->toISOString();
        $data['updated_at'] = $piece->updated_at->toISOString();

        return successResponse($data, __('piece.piece_details_retrieved'));
    }

    /**
     * Delete piece
     */
    public function destroy(Request $request, $pieceId): JsonResponse
    {
        $employee = $request->user();
        $vendorId = $employee->vendor_id;

        $piece = Piece::where('vendor_id', $vendorId)->find($pieceId);

        if (! $piece) {
            return notFoundResponse(__('piece.piece_not_found'));
        }

        // Delete the piece
        $piece->delete();

        return successResponse(null, __('piece.piece_deleted'));
    }

    /**
     * Filter pieces
     */
    public function filter(Request $request): JsonResponse
    {
        $employee = $request->user();
        $vendorId = $employee->vendor_id;
        $lang = app()->getLocale();
        $branchId = $request->query('branch_id');

        $validator = Validator::make($request->all(), [
            'name' => ['nullable', 'string'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $query = Piece::where('vendor_id', $vendorId)
            ->with(['iconRelation']);

        // Filter by branch relationship if provided
        if ($branchId) {
            $branchIdInt = (int) $branchId;
            $branch = Branch::where('id', $branchIdInt)->where('vendor_id', $vendorId)->first();
            if (! $branch) {
                return notFoundResponse(__('branch.branch_not_found_or_not_yours'));
            }

            $query->whereHas('branches', function ($q) use ($branchIdInt) {
                $q->where('branches.id', $branchIdInt);
            });
            $query->with([
                'services' => function ($q) use ($branchIdInt) {
                    $q->where('services.is_active', true)
                        ->where('service_piece.branch_id', $branchIdInt);
                },
            ]);
        }

        // Filter by name if provided
        if ($request->has('name') && $request->name) {
            $searchName = $request->name;
            $query->where(function ($q) use ($searchName) {
                $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(name, '$.ar')) LIKE ?", ["%{$searchName}%"])
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(name, '$.en')) LIKE ?", ["%{$searchName}%"]);
            });
        }

        $pieces = $query->get();
        $branchIdInt = $branchId ? (int) $branchId : null;
        $branchPivotMap = $branchIdInt
            ? PieceBranchOffering::pivotMapForPieces($branchIdInt, $pieces->pluck('id')->map(fn ($id) => (int) $id)->all())
            : [];
        $additionalByPiece = $branchIdInt
            ? PiecePricingFormatter::additionalServicesMapForPieces($pieces, $branchIdInt, $lang)
            : [];

        $pieces = $pieces->map(function ($piece) use ($lang, $branchIdInt, $additionalByPiece, $branchPivotMap) {
            $precomputed = $branchIdInt ? ($additionalByPiece[(int) $piece->id] ?? []) : null;
            $pivotRow = $branchIdInt ? ($branchPivotMap[(int) $piece->id] ?? null) : null;
            $data = $this->formatVendorPieceResponse($piece, $branchIdInt, $lang, $precomputed, $pivotRow);
            $data['is_active'] = (bool) $piece->is_active;

            return $data;
        });

        return successResponse($pieces, __('piece.filtered_pieces_retrieved'));
    }

    /**
     * Assign main services and/or additional services to piece
     * POST /api/v1/vendor/pieces/{pieceId}/additional-services
     *
     * Body:
     * - branch_id: optional; required when sending "services" (main services). When provided, piece is added to branch if needed.
     * - services: optional array of main services. Each: { service_id, price? }. branch_id required.
     * - additional_services: optional array. Each: { service_addition_id } only (price from service_additions catalog).
     * - Or single: service_addition_id (no price in request).
     */
    public function assignAdditionalService(Request $request, $pieceId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'branch_id' => ['nullable', 'exists:branches,id'],
            'services' => ['nullable', 'array'],
            'services.*.service_id' => ['required_with:services', 'exists:services,id'],
            'services.*.price' => ['nullable', 'numeric', 'min:0'],
            'additional_services' => ['nullable', 'array'],
            'additional_services.*.service_addition_id' => ['sometimes', 'integer', 'exists:service_additions,id'],
            'additional_services.*.price' => ['prohibited'],
            'service_addition_id' => ['nullable', 'exists:service_additions,id'],
            'price' => ['prohibited'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $hasMainServices = ! empty($request->services);
        $hasAdditionalArray = ! empty($request->additional_services);
        $hasSingleAdditional = $request->filled('service_addition_id');

        if (! $hasMainServices && ! $hasAdditionalArray && ! $hasSingleAdditional) {
            return validationErrorResponse(
                ['services' => [__('piece.provide_services_or_service_additions')]],
                'Validation failed',
                422
            );
        }

        if ($hasMainServices && ! $request->filled('branch_id')) {
            return validationErrorResponse(['branch_id' => ['branch_id is required when assigning main services.']]);
        }

        $employee = $request->user();
        $vendorId = $employee->vendor_id;

        $piece = Piece::where('vendor_id', $vendorId)->find($pieceId);
        if (! $piece) {
            return notFoundResponse(__('piece.piece_not_found'));
        }

        $branchId = $request->branch_id ? (int) $request->branch_id : null;
        $branch = null;
        $pieceAddedToBranch = false;

        if ($branchId) {
            $branch = \Modules\Branch\Models\Branch::where('id', $branchId)->where('vendor_id', $vendorId)->first();
            if (! $branch) {
                return notFoundResponse(__('branch.branch_not_found_or_not_yours'));
            }
            $pieceInBranch = $branch->pieces()->where('pieces.id', $pieceId)->exists();
            if (! $pieceInBranch) {
                $branch->pieces()->attach($pieceId);
                $pieceAddedToBranch = true;
            }
        }

        $assignedMainServices = [];
        $assignedAdditionalServices = [];
        $errors = [];

        // 1) Main services (branch required)
        if ($hasMainServices && $branch) {
            $syncResult = $this->syncServicesToPieceAtBranch($piece, $branch, $request->services, app()->getLocale());
            $assignedMainServices = $syncResult['services'];
            $errors = array_merge($errors, $syncResult['errors']);
        }

        // cache invalidation: service additions assigned
        flushCacheTags(["branch_{$branchId}", 'services', 'pieces', 'branches']);

        // 2) Additional services (array or single) — price from catalog only
        $additionsToProcess = $this->normalizeServiceAdditionsInput(
            $hasAdditionalArray
                ? $request->additional_services
                : ($hasSingleAdditional ? [['service_addition_id' => (int) $request->service_addition_id]] : [])
        );

        if (! empty($additionsToProcess)) {
            if ($branchId) {
                $additionResult = $this->syncServiceAdditionsToPieceAtBranch(
                    $piece,
                    $branchId,
                    $additionsToProcess,
                    $vendorId,
                    app()->getLocale(),
                    $hasAdditionalArray
                );
                $assignedAdditionalServices = $additionResult['service_additions'];
                $errors = array_merge($errors, $additionResult['errors']);
            } else {
                foreach ($additionsToProcess as $a) {
                    $serviceAddition = \Modules\Service\Models\ServiceAddition::where('id', $a['service_addition_id'])
                        ->where('vendor_id', $vendorId)
                        ->first();
                    if (! $serviceAddition) {
                        $errors[] = __('service.service_addition_not_found').' (ID: '.$a['service_addition_id'].')';

                        continue;
                    }
                    $catalogPrice = $serviceAddition->getCatalogPriceOrNull();
                    if ($catalogPrice === null) {
                        $errors[] = __('service.service_addition_price_not_set').' (ID: '.$serviceAddition->id.')';

                        continue;
                    }
                    $piece->additionalServices()->syncWithoutDetaching([
                        $a['service_addition_id'] => [
                            'price' => $catalogPrice,
                            'branch_id' => null,
                        ],
                    ]);
                    $assignedAdditionalServices[] = [
                        'service_addition_id' => $serviceAddition->id,
                        'service_addition_name' => $serviceAddition->getTranslation('name', app()->getLocale()),
                        'price' => $catalogPrice,
                        'branch_id' => null,
                    ];
                }
            }
        }

        // cache invalidation: piece services assigned
        if ($branchId) {
            flushCacheTags(["branch_{$branchId}", 'services', 'pieces', 'branches']);
        } else {
            flushCacheTags(['services', 'pieces', 'branches']);
        }

        $response = [
            'piece_id' => $piece->id,
            'piece_name' => $branch
                ? $piece->getDisplayNameAtBranch((int) $branch->id, app()->getLocale())
                : $piece->getTranslation('name', app()->getLocale()),
            'branch_id' => $branchId,
            'branch_name' => $branch ? $branch->getTranslation('name', app()->getLocale()) : null,
            'piece_added_to_branch' => $pieceAddedToBranch,
            'main_services' => $assignedMainServices,
            'additional_services' => $assignedAdditionalServices,
        ];

        if (! empty($errors)) {
            $response['errors'] = $errors;
        }

        $message = (count($assignedMainServices) > 0 || count($assignedAdditionalServices) > 0)
            ? __('piece.services_assigned_to_piece')
            : __('piece.no_services_assigned');

        return successResponse($response, $message, 201);
    }

    /**
     * Assign one piece to branch with many main services and many service additions
     * POST /api/v1/vendor/branches/{branchId}/assign-service-additions
     *
     * Body: piece_id, services[] ({ service_id, price }), service_additions[] ({ service_addition_id } only),
     *       name? ({ ar, en }) — branch-specific display name; does not change catalog pieces.name.
     * - Attaches piece to branch; service_addition price comes from service_additions catalog, not request.
     * - Top-level "price" on the piece is not used (pieces have no standalone price).
     * - For catalog-only piece create, use POST /vendor/pieces without branch_id or services.
     */
    public function assignServiceAdditionsToPieces(Request $request, $branchId): JsonResponse
    {
        $branchId = (int) $branchId;

        $validator = Validator::make($request->all(), [
            'piece_id' => ['required', 'integer', 'exists:pieces,id'],
            'name' => ['sometimes', 'array'],
            'name.ar' => ['required_with:name', 'string', 'max:255'],
            'name.en' => ['required_with:name', 'string', 'max:255'],
            'services' => ['nullable', 'array'],
            'services.*.service_id' => ['required', 'integer', 'exists:services,id'],
            'services.*.price' => ['required', 'numeric', 'min:0'],
            'service_additions' => ['nullable', 'array'],
            'service_additions.*.service_addition_id' => ['sometimes', 'integer', 'exists:service_additions,id'],
            'service_additions.*.price' => ['prohibited'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $hasServices = ! empty($request->input('services'));
        $hasServiceAdditionsInput = $request->has('service_additions');
        $hasBranchName = $request->has('name') && is_array($request->input('name'));

        if (! $hasServices && ! $hasServiceAdditionsInput && ! $hasBranchName) {
            return validationErrorResponse([
                'services' => [__('piece.provide_services_or_service_additions_or_name')],
            ]);
        }

        $employee = $request->user();
        $vendorId = $employee->vendor_id;
        $locale = app()->getLocale();

        $branch = Branch::where('id', $branchId)
            ->where('vendor_id', $vendorId)
            ->first();

        if (! $branch) {
            return notFoundResponse(__('branch.branch_not_found_or_not_yours'));
        }

        $pieceId = (int) $request->piece_id;
        $piece = Piece::where('id', $pieceId)->where('vendor_id', $vendorId)->first();

        if (! $piece) {
            return notFoundResponse(__('piece.piece_not_found'));
        }

        $pieceWasAlreadyInBranch = $branch->pieces()->where('pieces.id', $pieceId)->exists();
        $errors = [];
        $assignedServices = [];
        $assignedAdditions = [];

        if ($hasServices) {
            $syncResult = $this->syncServicesToPieceAtBranch($piece, $branch, $request->input('services'), $locale);
            $assignedServices = $syncResult['services'];
            $errors = array_merge($errors, $syncResult['errors']);
        } elseif (! $pieceWasAlreadyInBranch) {
            $branch->pieces()->attach($pieceId);
        }

        if ($hasServiceAdditionsInput) {
            $additionResult = $this->syncServiceAdditionsToPieceAtBranch(
                $piece,
                $branchId,
                $this->normalizeServiceAdditionsInput($request->input('service_additions', [])),
                $vendorId,
                $locale,
                true
            );
            $assignedAdditions = $additionResult['service_additions'];
            $errors = array_merge($errors, $additionResult['errors']);
        }

        if ($hasBranchName) {
            if (! $branch->pieces()->where('pieces.id', $pieceId)->exists()) {
                $branch->pieces()->attach($pieceId);
            }
            PieceBranchOffering::upsert($branchId, $piece, $request->input('name'));
        }

        $piece->load('iconRelation');
        $piece->load(['services' => function ($q) use ($branchId) {
            $q->where('services.is_active', true)
                ->where('service_piece.branch_id', $branchId);
        }]);

        $pivotRow = PieceBranchOffering::find($branchId, $pieceId);
        $response = [
            'branch_id' => $branch->id,
            'branch_name' => $branch->getTranslation('name', $locale),
            'piece_added_to_branch' => ! $pieceWasAlreadyInBranch,
            'piece' => $this->formatVendorPieceResponse($piece, $branchId, $locale, null, $pivotRow),
            'service_additions' => $assignedAdditions,
        ];

        if (! empty($errors)) {
            $response['errors'] = $errors;
        }

        flushCacheTags(["branch_{$branchId}", 'services', 'pieces', 'branches']);

        return successResponse($response, __('piece.service_additions_assigned_successfully'), 201);
    }

    /**
     * Validation rules for services[] on piece create/update/assign.
     */
    private function pieceServicesValidationRules(): array
    {
        return [
            'services' => ['nullable', 'array'],
            'services.*.service_id' => ['required_with:services', 'integer', 'exists:services,id'],
            'services.*.price' => ['required_with:services', 'numeric', 'min:0'],
        ];
    }

    /**
     * Assign main services to a piece at a branch (service_piece prices).
     *
     * @return array{services: array<int, array{service_id: int, service_name: string, price: float}>, errors: array<int, string>}
     */
    private function syncServicesToPieceAtBranch(Piece $piece, Branch $branch, array $servicesInput, string $lang): array
    {
        $branchId = (int) $branch->id;
        $assigned = [];
        $errors = [];

        if (! $branch->pieces()->where('pieces.id', $piece->id)->exists()) {
            $branch->pieces()->attach($piece->id);
        }

        foreach ($servicesInput as $serviceData) {
            $serviceId = (int) $serviceData['service_id'];
            $service = Service::find($serviceId);

            if (! $service) {
                $errors[] = __('service.service_not_found')." (ID: {$serviceId})";

                continue;
            }

            $price = (float) $serviceData['price'];

            $branchServicePivotData = [];
            if (isset($serviceData['description'])) {
                if (is_array($serviceData['description'])) {
                    $descriptionData = [
                        'ar' => $serviceData['description']['ar'] ?? '',
                        'en' => $serviceData['description']['en'] ?? '',
                    ];
                } else {
                    $descriptionData = [
                        'ar' => (string) $serviceData['description'],
                        'en' => (string) $serviceData['description'],
                    ];
                }
                $branchServicePivotData['description'] = json_encode($descriptionData);
            }

            $branchService = $branch->services()->where('services.id', $serviceId)->first();
            if ($branchService) {
                $branch->services()->updateExistingPivot($serviceId, $branchServicePivotData);
            } else {
                $branch->services()->attach($serviceId, $branchServicePivotData);
            }

            DB::table('service_piece')->upsert(
                [[
                    'service_id' => $serviceId,
                    'piece_id' => $piece->id,
                    'branch_id' => $branchId,
                    'price' => $price,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]],
                ['service_id', 'piece_id', 'branch_id'],
                ['price', 'updated_at']
            );

            $assigned[] = [
                'service_id' => $service->id,
                'service_name' => $service->getTranslation('service_name', $lang),
                'price' => $price,
            ];
        }

        return ['services' => $assigned, 'errors' => $errors];
    }

    /**
     * Accept [5, 6] or [{ "service_addition_id": 5 }, ...].
     *
     * @return array<int, array{service_addition_id: int}>
     */
    private function normalizeServiceAdditionsInput(array $input): array
    {
        $normalized = [];

        foreach ($input as $item) {
            if (is_numeric($item)) {
                $normalized[] = ['service_addition_id' => (int) $item];
            } elseif (is_array($item) && isset($item['service_addition_id'])) {
                $normalized[] = ['service_addition_id' => (int) $item['service_addition_id']];
            }
        }

        return $normalized;
    }

    /**
     * Link additional services to a piece at a branch (no per-piece price override).
     *
     * @param  array<int, array{service_addition_id: int}>  $additionsInput
     * @return array{service_additions: array<int, array{service_addition_id: int, service_addition_name: string, price: float}>, errors: array<int, string>}
     */
    private function syncServiceAdditionsToPieceAtBranch(
        Piece $piece,
        int $branchId,
        array $additionsInput,
        int $vendorId,
        string $lang,
        bool $replaceExisting = true
    ): array {
        $assigned = [];
        $errors = [];
        $idsToKeep = [];

        foreach ($additionsInput as $additionData) {
            $idsToKeep[] = (int) $additionData['service_addition_id'];
        }

        if ($replaceExisting) {
            $deleteQuery = DB::table('service_addition_piece')
                ->where('piece_id', $piece->id)
                ->where('branch_id', $branchId);

            if (! empty($idsToKeep)) {
                $deleteQuery->whereNotIn('service_addition_id', $idsToKeep);
            }

            $deleteQuery->delete();
        }

        foreach ($additionsInput as $additionData) {
            $serviceAdditionId = (int) $additionData['service_addition_id'];
            $serviceAddition = \Modules\Service\Models\ServiceAddition::where('id', $serviceAdditionId)
                ->where('vendor_id', $vendorId)
                ->first();

            if (! $serviceAddition) {
                $errors[] = __('service.service_addition_not_found')." (ID: {$serviceAdditionId})";

                continue;
            }

            $branchPrice = $serviceAddition->resolveBranchAssignmentPrice($branchId);
            if ($branchPrice === null) {
                $errors[] = __('service.service_addition_not_on_branch')." (ID: {$serviceAdditionId})";

                continue;
            }

            \Modules\Service\Support\ServiceAdditionBranchOffering::linkToPiece(
                $branchId,
                $piece->id,
                $serviceAddition,
                $branchPrice
            );

            $assigned[] = [
                'service_addition_id' => $serviceAddition->id,
                'service_addition_name' => $serviceAddition->getDisplayNameAtBranch($branchId, $lang),
                'price' => $branchPrice,
            ];
        }

        return ['service_additions' => $assigned, 'errors' => $errors];
    }

    /**
     * Piece has no standalone price. Each service on the piece has service_id + price (service_piece).
     */
    /**
     * @param  array<int, array<string, mixed>>|null  $additionalServices  Preloaded per-piece rows (avoids duplicate queries on index).
     */
    private function formatVendorPieceResponse(
        Piece $piece,
        ?int $branchId,
        string $lang,
        ?array $additionalServices = null,
        ?object $branchPivotRow = null
    ): array {
        $data = PiecePricingFormatter::pieceWithServices($piece, $branchId, $lang, $branchPivotRow);

        if ($additionalServices !== null) {
            $data['additional_services'] = $additionalServices;
        }

        if ($piece->iconRelation) {
            $data['icon'] = $this->uploadFilesService->getFullUrl($piece->iconRelation->path);
        }

        return $data;
    }

    private function respondWithPiecesAvailableForBranch(Request $request, int $branchId): JsonResponse
    {
        $employee = $request->user();
        $vendorId = $employee->vendor_id;
        $lang = app()->getLocale();

        $branch = Branch::where('id', $branchId)->where('vendor_id', $vendorId)->first();

        if (! $branch) {
            return notFoundResponse(__('branch.branch_not_found_or_not_yours'));
        }

        $pieces = Piece::query()
            ->where('vendor_id', $vendorId)
            ->whereDoesntHave('branches', function ($q) use ($branchId) {
                $q->where('branches.id', $branchId);
            })
            ->with('iconRelation')
            ->orderBy('id')
            ->get()
            ->map(fn (Piece $piece) => $this->formatPieceDropdownOption($piece, $lang));

        return successResponse([
            'branch_id' => $branchId,
            'branch_name' => $branch->getTranslation('name', $lang),
            'pieces' => $pieces,
        ], __('piece.pieces_available_for_branch_retrieved'));
    }

    /**
     * @return array{id: int, name: array{ar: string, en: string}, display_name: string, icon: ?string}
     */
    private function formatPieceDropdownOption(Piece $piece, string $lang): array
    {
        $names = PieceBranchOffering::catalogNameArray($piece);

        return [
            'id' => $piece->id,
            'name' => $names,
            'display_name' => $names[$lang] ?? $names['ar'] ?? $names['en'] ?? '',
            'icon' => $piece->iconRelation
                ? $this->uploadFilesService->getFullUrl($piece->iconRelation->path)
                : null,
        ];
    }
}
