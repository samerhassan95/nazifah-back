<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\UploadFilesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Branch\Models\Branch;
use Modules\Piece\Models\Piece;
use Modules\Service\Models\Service;
use Modules\Service\Models\ServiceAddition;
use Modules\Vendor\Models\Vendor;

class AdminLaundryPieceController extends Controller
{
    protected $uploadFilesService;

    public function __construct(UploadFilesService $uploadFilesService)
    {
        $this->uploadFilesService = $uploadFilesService;
    }

    /**
     * Get pieces for a laundry
     * GET /laundries/pieces
     */
    public function index(Request $request): JsonResponse
    {
        $vendorId = $request->input('vendor_id');

        if (! $vendorId) {
            return errorResponse('Vendor ID is required', null, 400);
        }

        $vendor = Vendor::find($vendorId);

        if (! $vendor) {
            return notFoundResponse('Vendor not found');
        }

        $pieces = Piece::where('vendor_id', $vendorId)
            ->with(['services', 'additionalServices'])
            ->paginate($request->input('per_page', 15));

        $piecesData = $pieces->getCollection()->map(function ($piece) use ($vendorId) {
            $locale = app()->getLocale();

            // Get additional services directly associated with this piece
            $additionalServices = $piece->additionalServices()
                ->get()
                ->map(function ($service) use ($locale) {
                    return [
                        'id' => $service->id,
                        'name' => $service->getTranslation('name', $locale),
                        'icon_id' => $service->icon_id,
                    ];
                })
                ->toArray();

            $branches = Branch::where('vendor_id', $vendorId)
                ->pluck('name')
                ->map(function ($name) use ($locale) {
                    return is_array($name) ? ($name[$locale] ?? $name['ar'] ?? $name['en'] ?? '') : $name;
                })
                ->toArray();

            $services = $piece->services->map(function ($service) use ($locale) {
                return [
                    'service_id' => $service->id,
                    'service_name' => $service->getTranslation('service_name', $locale),
                    'price' => (float) ($service->pivot->price ?? 0),
                ];
            })->values()->all();

            return [
                'id' => $piece->id,
                'vendor_id' => $piece->vendor_id,
                'icon' => $piece->icon ?? null,
                'name' => $piece->getTranslation('name', $locale),
                'services' => $services,
                'additional_services' => $additionalServices,
                'Branches' => $branches,
            ];
        });

        $pieces->setCollection($piecesData);

        return successResponse($pieces, 'Pieces retrieved successfully');
    }

    /**
     * Get single piece
     * GET /laundries/pieces/:id
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $piece = Piece::with(['services', 'additionalServices'])->find($id);

        if (! $piece) {
            return notFoundResponse('Piece not found');
        }

        $vendorId = $request->input('vendor_id');
        $locale = $request->header('Accept-Language', 'ar');
        $additionalServices = [];
        $branches = [];

        if ($vendorId) {
            // Get additional services directly associated with this piece
            $additionalServices = $piece->additionalServices()
                ->get()
                ->map(function ($service) {
                    return [
                        'id' => $service->id,
                        'name' => [
                            'ar' => $service->getTranslation('name', 'ar'),
                            'en' => $service->getTranslation('name', 'en'),
                        ],
                        'icon_id' => $service->icon_id,
                    ];
                })
                ->toArray();

            $branches = Branch::where('vendor_id', $vendorId)
                ->pluck('name')
                ->map(function ($name) use ($locale) {
                    return is_array($name) ? ($name[$locale] ?? $name['ar'] ?? $name['en'] ?? '') : $name;
                })
                ->toArray();
        }

        $services = $piece->services->map(function ($service) {
            return [
                'service_id' => $service->id,
                'service_name' => [
                    'ar' => $service->getTranslation('service_name', 'ar'),
                    'en' => $service->getTranslation('service_name', 'en'),
                ],
                'price' => (float) ($service->pivot->price ?? 0),
            ];
        })->values()->all();

        $pieceData = [
            'id' => $piece->id,
            'vendor_id' => $piece->vendor_id,
            'icon' => $piece->iconRelation,
            'name' => [
                'ar' => $piece->getTranslation('name', 'ar'),
                'en' => $piece->getTranslation('name', 'en'),
            ],
            'services' => $services,
            'additional_services' => $additionalServices,
            'Branches' => $branches,
        ];

        return successResponse($pieceData, 'Piece retrieved successfully');
    }

    /**
     * Create piece
     * POST /laundries/pieces
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vendor_id' => 'required|exists:vendors,id',
            'service_id' => 'sometimes|exists:services,id',
            'service_ids' => 'nullable|array',
            'service_ids.*.service_id' => 'required_with:service_ids|exists:services,id',
            'service_ids.*.price' => 'required_with:service_ids|numeric|min:0',
            'icon_id' => 'required|exists:icons,id',
            'piece_name' => 'required|array',
            'piece_name.ar' => 'required|string|max:255',
            'piece_name.en' => 'required|string|max:255',
            'Price' => 'sometimes|numeric|min:0',
            'additional_services' => 'nullable|array',
            'additional_services.*.service_addition_id' => 'required_with:additional_services|exists:service_additions,id',
            'additional_services.*.branch_id' => 'required_with:additional_services|exists:branches,id',
            'additional_services.*.price' => 'required_with:additional_services|numeric|min:0',
            'additional_services.*.icon_id' => 'nullable|exists:icons,id',
            'Branches' => 'nullable|array',
        ]);

        $pieceData = [
            'vendor_id' => $validated['vendor_id'],
            'name' => ['ar' => $validated['piece_name'], 'en' => $validated['piece_name']],
            'icon_id' => $validated['icon_id'] ?? null,
            'is_active' => true,
        ];

        $piece = Piece::create($pieceData);

        // Handle service associations (support both old and new format)
        if (isset($validated['service_ids']) && ! empty($validated['service_ids'])) {
            // New format: multiple services with individual prices
            $servicePivotData = [];
            foreach ($validated['service_ids'] as $serviceData) {
                $servicePivotData[$serviceData['service_id']] = [
                    'price' => $serviceData['price'],
                ];
            }
            $piece->services()->attach($servicePivotData);
        } elseif (isset($validated['service_id']) && isset($validated['Price'])) {
            // Old format: single service with single price (backward compatibility)
            $piece->services()->attach($validated['service_id'], [
                'price' => $validated['Price'],
            ]);
        }

        // Attach additional services with branch-specific prices and update icon_id
        if (isset($validated['additional_services']) && is_array($validated['additional_services'])) {
            foreach ($validated['additional_services'] as $additionalService) {
                // Update the service addition's icon_id if provided
                if (isset($additionalService['icon_id'])) {
                    ServiceAddition::where('id', $additionalService['service_addition_id'])
                        ->update(['icon_id' => $additionalService['icon_id']]);
                }

                $piece->additionalServices()->attach($additionalService['service_addition_id'], [
                    'branch_id' => $additionalService['branch_id'],
                    'price' => $additionalService['price'],
                ]);
            }
        }

        // cache invalidation: piece created with associations
        flushCacheTags(['pieces', 'branches', 'services']);

        return successResponse($this->formatPiece($piece->fresh(), $validated['vendor_id'], $request->header('Accept-Language', 'ar')), 'Piece created successfully', 201);
    }

    /**
     * Update piece
     * PUT /laundries/pieces/:id
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $piece = Piece::find($id);

        if (! $piece) {
            return notFoundResponse('Piece not found');
        }

        $validated = $request->validate([
            'service_id' => 'sometimes|exists:services,id',
            'service_ids' => 'nullable|array',
            'service_ids.*.service_id' => 'required_with:service_ids|exists:services,id',
            'service_ids.*.price' => 'required_with:service_ids|numeric|min:0',
            'icon_id' => 'nullable|exists:icons,id',
            'piece_name' => 'sometimes|array',
            'piece_name.ar' => 'sometimes|string|max:255',
            'piece_name.en' => 'sometimes|string|max:255',
            'Price' => 'sometimes|numeric|min:0',
            'additional_services' => 'nullable|array',
            'additional_services.*.service_addition_id' => 'required_with:additional_services|exists:service_additions,id',
            'additional_services.*.branch_id' => 'required_with:additional_services|exists:branches,id',
            'additional_services.*.price' => 'required_with:additional_services|numeric|min:0',
            'additional_services.*.icon_id' => 'nullable|exists:icons,id',
            'Branches' => 'nullable|array',
        ]);

        $pieceData = [];

        if (isset($validated['piece_name'])) {
            $pieceData['name'] = ['ar' => $validated['piece_name'], 'en' => $validated['piece_name']];
        }

        if (isset($validated['icon_id'])) {
            $pieceData['icon_id'] = $validated['icon_id'];
        }

        $piece->update($pieceData);

        // Handle service associations (support both old and new format)
        if (isset($validated['service_ids'])) {
            // New format: multiple services with individual prices
            if (empty($validated['service_ids'])) {
                // Detach all services if empty array provided
                $piece->services()->detach();
                // cache invalidation
                flushCacheTags(['pieces', 'branches', 'services']);
            } else {
                $servicePivotData = [];
                foreach ($validated['service_ids'] as $serviceData) {
                    $servicePivotData[$serviceData['service_id']] = [
                        'price' => $serviceData['price'],
                    ];
                }
                $piece->services()->sync($servicePivotData);
                // cache invalidation
                flushCacheTags(['pieces', 'branches', 'services']);
            }
        } elseif (isset($validated['service_id']) || isset($validated['Price'])) {
            // Old format: single service with single price (backward compatibility)
            $serviceId = $validated['service_id'] ?? $piece->services()->first()?->id;
            $price = $validated['Price'] ?? $piece->services()->wherePivot('service_id', $serviceId)->first()?->pivot->price ?? 0;

            if ($serviceId) {
                $piece->services()->sync([
                    $serviceId => ['price' => $price],
                ]);
                // cache invalidation
                flushCacheTags(['pieces', 'branches', 'services']);
            }
        }

        // Update additional services with branch-specific prices and icon_id
        if (isset($validated['additional_services']) && is_array($validated['additional_services'])) {
            // Sync additional services (this will replace existing ones)
            $syncData = [];
            foreach ($validated['additional_services'] as $additionalService) {
                $key = $additionalService['service_addition_id'].'_'.$additionalService['branch_id'];
                $syncData[$additionalService['service_addition_id']] = [
                    'branch_id' => $additionalService['branch_id'],
                    'price' => $additionalService['price'],
                ];
            }
            // Note: Since we have branch_id in pivot, we need to handle this differently
            // For now, we'll detach all and reattach
            $piece->additionalServices()->detach();
            foreach ($validated['additional_services'] as $additionalService) {
                // Update the service addition's icon_id if provided
                if (isset($additionalService['icon_id'])) {
                    ServiceAddition::where('id', $additionalService['service_addition_id'])
                        ->update(['icon_id' => $additionalService['icon_id']]);
                }

                $piece->additionalServices()->attach($additionalService['service_addition_id'], [
                    'branch_id' => $additionalService['branch_id'],
                    'price' => $additionalService['price'],
                ]);
            }
            // cache invalidation: additional services sync
            flushCacheTags(['pieces', 'branches', 'services']);
        }

        $vendorId = $request->input('vendor_id', $piece->vendor_id);

        return successResponse($this->formatPiece($piece->fresh(), $vendorId, $request->header('Accept-Language', 'ar')), 'Piece updated successfully');
    }

    /**
     * Delete piece
     * DELETE /laundries/pieces/:id
     */
    public function destroy(int $id): JsonResponse
    {
        $piece = Piece::find($id);

        if (! $piece) {
            return notFoundResponse('Piece not found');
        }

        $piece->delete();

        return successResponse(null, 'Piece deleted successfully');
    }

    /**
     * Format piece data for response
     */
    private function formatPiece($piece, $vendorId = null, $locale = 'ar'): array
    {
        $additionalServices = [];
        $branches = [];

        if ($vendorId) {
            // Get additional services directly associated with this piece
            $additionalServices = $piece->additionalServices()
                ->get()
                ->map(function ($service) {
                    return [
                        'id' => $service->id,
                        'name' => [
                            'ar' => $service->getTranslation('name', 'ar'),
                            'en' => $service->getTranslation('name', 'en'),
                        ],
                        'icon_id' => $service->icon_id,
                    ];
                })
                ->toArray();

            $branches = Branch::where('vendor_id', $vendorId)
                ->pluck('name')
                ->map(function ($name) use ($locale) {
                    return is_array($name) ? ($name[$locale] ?? $name['ar'] ?? $name['en'] ?? '') : $name;
                })
                ->toArray();
        }

        $services = $piece->services->map(function ($service) {
            return [
                'service_id' => $service->id,
                'service_name' => [
                    'ar' => $service->getTranslation('service_name', 'ar'),
                    'en' => $service->getTranslation('service_name', 'en'),
                ],
                'price' => (float) ($service->pivot->price ?? 0),
            ];
        })->values()->all();

        return [
            'id' => $piece->id,
            'vendor_id' => $piece->vendor_id,
            'icon_id' => $piece->icon_id,
            'icon' => $piece->icon,
            'piece_name' => [
                'ar' => $piece->getTranslation('name', 'ar'),
                'en' => $piece->getTranslation('name', 'en'),
            ],
            'services' => $services,
            'additional_services' => $additionalServices,
            'Branches' => $branches,
        ];
    }
}
