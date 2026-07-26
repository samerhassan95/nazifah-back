<?php

namespace Modules\Address\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Modules\Address\Models\Address;
use Modules\Zone\Services\ZoneService;

class LocationController extends Controller
{
    protected ZoneService $zoneService;

    public function __construct(ZoneService $zoneService)
    {
        $this->zoneService = $zoneService;
    }

    /**
     * Get all user locations/addresses
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $perPage = $request->per_page ?? 15;
        $page = $request->page ?? 1;
        $tags = ["user_{$user->id}_addresses", 'zones'];
        $versionKey = getTagVersionKey($tags);
        $cacheKey = "user:{$user->id}:addresses:v{$versionKey}:p{$perPage}:page{$page}";

        $addresses = Cache::remember($cacheKey, 3600, function () use ($user, $perPage) {
            $query = Address::where('client_id', $user->id)
                ->with('zone')
                ->orderBy('is_default', 'desc')
                ->orderBy('created_at', 'desc');

            $results = $query->paginate($perPage);

            $results->getCollection()->transform(function ($address) {
                $data = [
                    'address_id' => $address->id,
                    'nickname' => $address->title,
                    'address_text' => $this->formatAddressText($address),
                    'building_number' => $address->building_number,
                    'street_number' => $address->street_number,
                    ...$address->getApiFloorAttributes(),
                    'apartment' => $address->apartment,
                    'latitude' => (float) $address->latitude,
                    'longitude' => (float) $address->longitude,
                    'notes' => $address->notes,
                    'is_default' => $address->is_default,
                    'national_address' => $address->national_address,
                ];

                if ($address->zone) {
                    $data['zone'] = [
                        'id' => $address->zone->id,
                        'name' => $address->zone->name,
                        'delivery_fee' => (float) $address->zone->delivery_fee,
                        'minimum_order' => (float) $address->zone->minimum_order,
                        'zone_color' => $address->zone->zone_color,
                    ];
                }

                return $data;
            });

            return $results;
        });

        return successResponse($addresses, __('address.addresses_retrieved'));
    }

    /**
     * Get single address details
     */
    public function show(Request $request, string $address_id): JsonResponse
    {
        $user = $request->user();
        $tags = ["user_{$user->id}_addresses", 'zones'];
        $versionKey = getTagVersionKey($tags);
        $cacheKey = "user:{$user->id}:address:{$address_id}:v{$versionKey}";

        $data = Cache::remember($cacheKey, 3600, function () use ($user, $address_id) {
            $address = Address::where('id', $address_id)
                ->where('client_id', $user->id)
                ->with('zone')
                ->first();

            if (! $address) {
                return null;
            }

            $addressData = [
                'address_id' => $address->id,
                'nickname' => $address->title,
                'address_text' => $this->formatAddressText($address),
                'building_number' => $address->building_number,
                'street_number' => $address->street_number,
                ...$address->getApiFloorAttributes(),
                'apartment' => $address->apartment,
                'latitude' => (float) $address->latitude,
                'longitude' => (float) $address->longitude,
                'notes' => $address->notes,
                'is_default' => $address->is_default,
                'national_address' => $address->national_address,
            ];

            if ($address->zone) {
                $addressData['zone'] = [
                    'id' => $address->zone->id,
                    'name' => $address->zone->name,
                    'delivery_fee' => (float) $address->zone->delivery_fee,
                    'minimum_order' => (float) $address->zone->minimum_order,
                    'zone_color' => $address->zone->zone_color,
                ];
            }

            return $addressData;
        });

        if (! $data) {
            return notFoundResponse(__('address.address_not_found'));
        }

        return successResponse($data, __('address.addresses_retrieved'));
    }

    /**
     * Add new location
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'nickname' => ['nullable', 'string', 'max:100'],
            'address_text' => ['nullable', 'string', 'max:500'],
            'building_number' => ['nullable', 'string', 'max:50'],
            'street_number' => ['nullable', 'string', 'max:50'],
            'floor' => ['nullable', 'string', 'max:50'],
            'floor_number' => ['required', 'string', 'max:50'],
            'apartment' => ['nullable', 'string', 'max:50'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_default' => ['nullable', 'boolean'],
            'national_address' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $user = $request->user();

        // Validate location and attach zone when available.
        // If location is outside zones, allow saving with null zone_id.
        $zoneValidation = $this->zoneService->validateLocation($request->latitude, $request->longitude);
        $zone = $zoneValidation['zone'] ?? null;

        // If setting as default, unset other defaults
        if ($request->is_default) {
            Address::where('client_id', $user->id)->update(['is_default' => false]);
        }

        $address = Address::create([
            'client_id' => $user->id,
            'zone_id' => $zone?->id,
            'title' => $request->nickname ?? null,
            'street_name' => $request->address_text ?? null,
            'building_number' => $request->building_number ?? null,
            'street_number' => $request->street_number ?? null,
            'floor' => $request->floor ?? null,
            'floor_number' => $request->floor_number ?? null,
            'apartment' => $request->apartment ?? null,
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
            'notes' => $request->notes ?? null,
            'is_default' => $request->is_default ?? false,
            'national_address' => $request->national_address ?? null,
        ]);

        flushCacheTags(["user_{$user->id}_addresses", 'addresses']);

        return successResponse([
            'address_id' => $address->id,
            'nickname' => $address->title,
            'address_text' => $request->address_text,
            'building_number' => $address->building_number,
            'street_number' => $address->street_number,
            ...$address->getApiFloorAttributes(),
            'apartment' => $address->apartment,
            'latitude' => (float) $address->latitude,
            'longitude' => (float) $address->longitude,
            'notes' => $address->notes,
            'is_default' => $address->is_default,
            'national_address' => $address->national_address,
            'zone' => $zone ? [
                'id' => $zone->id,
                'name' => $zone->name,
                'delivery_fee' => (float) $zone->delivery_fee,
                'minimum_order' => (float) $zone->minimum_order,
                'zone_color' => $zone->zone_color,
            ] : null,
        ], __('address.address_created'), 201);
    }

    /**
     * Update location
     */
    public function update(Request $request, int $address_id): JsonResponse
    {
        $user = $request->user();

        $address = Address::where('id', $address_id)
            ->where('client_id', $user->id)
            ->first();

        if (! $address) {
            return notFoundResponse(__('address.address_not_found'));
        }

        $validator = Validator::make($request->all(), [
            'nickname' => ['nullable', 'string', 'max:100'],
            'address_text' => ['nullable', 'string', 'max:500'],
            'building_number' => ['nullable', 'string', 'max:50'],
            'street_number' => ['nullable', 'string', 'max:50'],
            'floor' => ['nullable', 'string', 'max:50'],
            'floor_number' => ['nullable', 'string', 'max:50'],
            'apartment' => ['nullable', 'string', 'max:50'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'national_address' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        // If coordinates are being updated, validate the new location is in a zone
        $updateData = [
            'title' => $request->nickname ?? $address->title,
            'street_name' => $request->address_text ?? $address->street_name,
            'building_number' => $request->building_number ?? $address->building_number,
            'street_number' => $request->street_number ?? $address->street_number,
            'floor' => $request->has('floor') ? $request->floor : $address->floor,
            'floor_number' => $request->has('floor_number') ? $request->floor_number : $address->floor_number,
            'apartment' => $request->apartment ?? $address->apartment,
            'latitude' => $request->latitude ?? $address->latitude,
            'longitude' => $request->longitude ?? $address->longitude,
            'notes' => $request->notes ?? $address->notes,
            'national_address' => $request->national_address ?? $address->national_address,
        ];

        // Recalculate zone if coordinates changed.
        // If coordinates are outside zones, keep zone_id as null.
        if ($request->has('latitude') || $request->has('longitude')) {
            $lat = $request->latitude ?? $address->latitude;
            $long = $request->longitude ?? $address->longitude;

            $zoneValidation = $this->zoneService->validateLocation($lat, $long);
            $updateData['zone_id'] = $zoneValidation['zone']->id ?? null;
        }

        $address->update($updateData);

        // Reload the address with zone relationship
        $address->load('zone');

        $responseData = [
            'address_id' => $address->id,
            'nickname' => $address->title,
            'address_text' => $address->street_name,
            'building_number' => $address->building_number,
            'street_number' => $address->street_number,
            ...$address->getApiFloorAttributes(),
            'apartment' => $address->apartment,
            'latitude' => (float) $address->latitude,
            'longitude' => (float) $address->longitude,
            'notes' => $address->notes,
            'is_default' => $address->is_default,
            'national_address' => $address->national_address,
        ];

        if ($address->zone) {
            $responseData['zone'] = [
                'id' => $address->zone->id,
                'name' => $address->zone->name,
                'delivery_fee' => (float) $address->zone->delivery_fee,
                'minimum_order' => (float) $address->zone->minimum_order,
                'zone_color' => $address->zone->zone_color,
            ];
        }

        flushCacheTags(["user_{$user->id}_addresses", 'addresses']);

        return successResponse([
            'address' => $responseData,
        ], __('address.address_updated'));
    }

    /**
     * Delete location
     */
    public function destroy(Request $request, string $address_id): JsonResponse
    {
        $user = $request->user();

        $address = Address::where('id', $address_id)
            ->where('client_id', $user->id)
            ->first();

        if (! $address) {
            return notFoundResponse(__('address.address_not_found'));
        }

        $address->delete();

        flushCacheTags(["user_{$user->id}_addresses", 'addresses']);

        return successResponse(null, __('address.address_deleted'));
    }

    /**
     * Set default location
     */
    public function setDefault(Request $request, string $address_id): JsonResponse
    {
        $user = $request->user();

        $address = Address::where('id', $address_id)
            ->where('client_id', $user->id)
            ->first();

        if (! $address) {
            return notFoundResponse(__('address.address_not_found'));
        }

        // Unset all defaults first
        Address::where('client_id', $user->id)->update(['is_default' => false]);

        // Set this as default
        $address->update(['is_default' => true]);

        flushCacheTags(["user_{$user->id}_addresses", 'addresses']);

        return successResponse([
            'address' => [
                'address_id' => $address->id,
                'nickname' => $address->title,
                'is_default' => true,
            ],
        ], __('address.default_address_set'));
    }

    /**
     * Validate coordinates and check if inside a zone
     * Returns zone info if inside, or nearest zone if outside
     */
    public function validateZone(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $latitude = (float) $request->input('latitude');
        $longitude = (float) $request->input('longitude');

        // Fuzzy cache key (4 decimal places ~11m)
        $fuzzyLat = round($latitude, 4);
        $fuzzyLong = round($longitude, 4);
        $tags = ['zones'];
        $versionKey = getTagVersionKey($tags);
        $cacheKey = "zone:validation:{$fuzzyLat}:{$fuzzyLong}:v{$versionKey}";

        $result = Cache::remember($cacheKey, 600, function () use ($latitude, $longitude) {
            // Check if coordinates are inside any zone
            $zone = \Modules\Zone\Models\Zone::findZoneByCoordinates($latitude, $longitude);

            if ($zone) {
                return [
                    'success' => true,
                    'data' => [
                        'inside_zone' => true,
                        'zone' => [
                            'id' => $zone->id,
                            'name' => $zone->name,
                            'code' => $zone->code,
                            'delivery_fee' => (float) $zone->delivery_fee,
                            'minimum_order' => (float) $zone->minimum_order,
                            'description' => $zone->description,
                            'zone_color' => $zone->zone_color,
                        ],
                        'coordinates' => [
                            'latitude' => $latitude,
                            'longitude' => $longitude,
                        ],
                    ],
                    'message_key' => 'zones.location_valid',
                ];
            }

            // Coordinates are outside all zones - find nearest zone
            $nearestZoneData = \Modules\Zone\Models\Zone::findNearestZoneByCoordinates($latitude, $longitude);

            if (! $nearestZoneData) {
                return [
                    'success' => false,
                    'error_code' => 404,
                    'message_key' => 'zones.location_not_in_service_area',
                ];
            }

            $nearestZone = $nearestZoneData['zone'];
            $distance = $nearestZoneData['distance'];
            $center = $nearestZoneData['center'];

            return [
                'success' => true,
                'data' => [
                    'inside_zone' => false,
                    'message' => __('zones.location_not_in_service_area'),
                    'nearest_zone' => [
                        'id' => $nearestZone->id,
                        'name' => $nearestZone->name,
                        'code' => $nearestZone->code,
                        'delivery_fee' => (float) $nearestZone->delivery_fee,
                        'minimum_order' => (float) $nearestZone->minimum_order,
                        'description' => $nearestZone->description,
                        'zone_color' => $nearestZone->zone_color,
                        'distance_km' => round($distance, 2),
                        'center_location' => [
                            'latitude' => $center['latitude'],
                            'longitude' => $center['longitude'],
                            'map_url' => sprintf(
                                'https://www.google.com/maps?q=%s,%s',
                                $center['latitude'],
                                $center['longitude']
                            ),
                        ],
                    ],
                    'provided_coordinates' => [
                        'latitude' => $latitude,
                        'longitude' => $longitude,
                    ],
                ],
                'message_key' => 'zones.location_not_in_service_area_with_nearest',
            ];
        });

        if (isset($result['success']) && ! $result['success']) {
            return errorResponse(__($result['message_key']), null, $result['error_code']);
        }

        return successResponse($result['data'], __($result['message_key']));
    }

    /**
     * Format address text from address model
     */
    private function formatAddressText(Address $address): string
    {
        $parts = array_filter([
            $address->street_name,
            $address->building_number ? __('address.building').' '.$address->building_number : null,
            $address->floor !== null && $address->floor !== '' ? __('address.floor').': '.$address->floor : null,
            $address->floor_number !== null && $address->floor_number !== '' ? __('address.floor_number').': '.$address->floor_number : null,
            $address->apartment ? __('address.apartment').' '.$address->apartment : null,
        ]);

        return implode(', ', $parts);
    }
}
