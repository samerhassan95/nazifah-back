<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ErrorResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Http\Requests\StoreClientRequest;
use Modules\Admin\Http\Requests\UpdateClientRequest;
use Modules\Admin\Http\Resources\ClientResource;
use Modules\Admin\Services\ClientService;
use Modules\Client\Models\Client;
use Modules\Order\Models\Order;

class AdminClientController extends Controller
{
    public function __construct(
        private ClientService $clientService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = [
            'is_verified' => $request->is_verified,
            'is_banned' => $request->is_banned,
            'zone_id' => $request->zone_id,
            'search' => $request->search,
            'sort_by' => $request->input('sort_by', 'created_at'),
            'sort_order' => $request->input('sort_order', 'desc'),
        ];

        $clients = $this->clientService->getAllPaginated(
            $filters,
            $request->input('per_page', 15)
        );

        return successResponse(
            ClientResource::collection($clients),
            __('admin::client.clients_retrieved')
        );
    }

    public function store(StoreClientRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Handle image upload (can be image or file)
        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $mimeType = $file->getMimeType();

            if (str_starts_with($mimeType, 'image/')) {
                $uploadService = app(\App\Services\UploadFilesService::class);
                $data['image'] = $uploadService->uploadImage($file, 'clients/images');
            } else {
                $uploadService = app(\App\Services\UploadFilesService::class);
                $data['image'] = $uploadService->uploadFile($file, 'clients/files');
            }
        }

        $client = $this->clientService->create($data);

        return successResponse(new ClientResource($client), __('admin::client.client_created'), 201);
    }

    public function update(UpdateClientRequest $request, int $id): JsonResponse
    {
        $client = $this->clientService->find($id);

        if (! $client) {
            return ErrorResponse::make(__('admin::client.client_not_found'), null, 404);
        }

        $data = $request->validated();

        // Handle image upload (can be image or file)
        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $mimeType = $file->getMimeType();

            // Check if it's an image
            if (str_starts_with($mimeType, 'image/')) {
                $uploadService = app(\App\Services\UploadFilesService::class);
                $data['image'] = $uploadService->uploadImage($file, 'clients/images', $client->image);
            } else {
                // Upload as file (PDF, DOC, etc.)
                $uploadService = app(\App\Services\UploadFilesService::class);
                $data['image'] = $uploadService->uploadFile($file, 'clients/files');
            }
        }

        $client = $this->clientService->update($id, $data);

        return successResponse(
            new ClientResource($client),
            __('admin::client.client_updated')
        );
    }

    public function destroy(int $id): JsonResponse
    {
        $deleted = $this->clientService->delete($id);

        if (! $deleted) {
            return ErrorResponse::make(__('admin::client.client_not_found'), null, 404);
        }

        return successResponse(null, __('admin::client.client_deleted'));
    }

    public function toggleStatus(int $id): JsonResponse
    {
        $client = $this->clientService->toggleStatus($id);

        if (! $client) {
            return ErrorResponse::make(__('admin::client.client_not_found'), null, 404);
        }

        return successResponse(
            new ClientResource($client),
            __('admin::client.client_status_toggled')
        );
    }

    /**
     * Ban a client
     * POST /clients/{id}/ban
     */
    public function ban(Request $request, int $id): JsonResponse
    {
        $client = Client::find($id);

        if (! $client) {
            return ErrorResponse::make(__('admin::client.client_not_found'), null, 404);
        }

        $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $client->update([
            'is_banned' => true,
            'ban_reason' => $request->input('reason'),
            'banned_at' => now(),
        ]);

        return successResponse(
            new ClientResource($client->fresh()),
            __('admin::client.client_banned')
        );
    }

    /**
     * Unban a client
     * POST /clients/{id}/unban
     */
    public function unban(int $id): JsonResponse
    {
        $client = Client::find($id);

        if (! $client) {
            return ErrorResponse::make(__('admin::client.client_not_found'), null, 404);
        }

        $client->update([
            'is_banned' => false,
            'ban_reason' => null,
            'banned_at' => null,
        ]);

        return successResponse(
            new ClientResource($client->fresh()),
            __('admin::client.client_unbanned')
        );
    }

    public function statistics(Request $request): JsonResponse
    {
        $zoneId = $request->input('zone_id');
        $stats = $this->clientService->getStatistics($zoneId);

        // Get top requested clients (max 10) - filter by zone_id if provided
        $topClientsQuery = Order::select('client_id', DB::raw('COUNT(*) as requests_count'))
            ->whereNotNull('client_id')
            ->with('client');

        // Filter by zone_id if provided
        if ($zoneId) {
            $topClientsQuery->whereHas('client.addresses', function ($q) use ($zoneId) {
                $q->where('zone_id', $zoneId);
            });
        }

        $topClients = $topClientsQuery
            ->groupBy('client_id')
            ->orderBy('requests_count', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($order) {
                $clientName = 'Unknown';
                if ($order->client) {
                    $fullName = $order->client->full_name;
                    if (is_array($fullName)) {
                        $clientName = $fullName['ar'] ?? $fullName['en'] ?? 'Unknown';
                    } else {
                        $clientName = $fullName ?? 'Unknown';
                    }
                }

                return [
                    'Client_name' => $clientName,
                    'Requests_count' => $order->requests_count,
                ];
            });

        $stats['top_requested_clients'] = $topClients;

        return successResponse(
            $stats,
            __('admin::client.client_statistics_retrieved')
        );
    }

    /**
     * Get clients per city
     * GET /clients/clients_per_city
     */
    public function clientsPerCity(Request $request): JsonResponse
    {
        // Get clients grouped by zone/city
        // Use distinct to count unique clients per zone
        $clientsPerCity = DB::table('clients')
            ->join('addresses', 'clients.id', '=', 'addresses.client_id')
            ->join('zones', 'addresses.zone_id', '=', 'zones.id')
            ->select('zones.id', 'zones.name', DB::raw('COUNT(DISTINCT clients.id) as count'))
            ->groupBy('zones.id', 'zones.name')
            ->orderBy('count', 'desc')
            ->limit(13)
            ->get()
            ->map(function ($item) {
                // Get Arabic name from JSON field
                $zoneName = $item->name;
                $cityName = 'Unknown';

                if (is_string($zoneName)) {
                    $decoded = json_decode($zoneName, true);
                    if (is_array($decoded)) {
                        $cityName = $decoded['ar'] ?? $decoded['en'] ?? 'Unknown';
                    } else {
                        $cityName = $zoneName;
                    }
                } elseif (is_array($zoneName)) {
                    $cityName = $zoneName['ar'] ?? $zoneName['en'] ?? 'Unknown';
                }

                return [
                    'city' => $cityName,
                    'count' => (int) $item->count,
                ];
            });

        return successResponse([
            'customers_by_city' => $clientsPerCity,
        ], 'Clients per city retrieved successfully');
    }

    public function show(int $id): JsonResponse
    {
        $client = Client::with(['addresses'])->find($id);

        if (! $client) {
            return ErrorResponse::make(__('admin::client.client_not_found'), null, 404);
        }

        // Get client orders with branch.vendor relationship
        $orders = Order::where('client_id', $client->id)
            ->with(['branch.vendor', 'items.piece'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($order) {
                $piecesCount = $order->items ? $order->items->sum('quantity') : 0;
                $vendorName = 'Unknown';
                if ($order->branch && $order->branch->vendor) {
                    $vendorName = is_array($order->branch->vendor->name)
                        ? ($order->branch->vendor->name['ar'] ?? $order->branch->vendor->name['en'] ?? 'Unknown')
                        : ($order->branch->vendor->name ?? 'Unknown');
                }

                return [
                    'id' => $order->id,
                    'order_code' => "#{$order->order_number}",
                    'Pieces_count' => $piecesCount,
                    'Laundry' => $vendorName,
                    'Order_value' => (float) $order->final_amount,
                    'Order_date' => $order->created_at->format('d-m-Y'),
                    'Order_status' => $order->status,
                    'status_label' => $order->status_label,
                    'rating' => $order->rating ? (float) $order->rating : null,
                    'review' => $order->review,
                ];
            });

        // Get wallet statistics
        $walletBalance = $client->wallet_balance ?? 0;

        // Get wallet transactions
        $walletTransactions = DB::table('wallet_transactions')
            ->where('client_id', $client->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($transaction) {
                return [
                    'Process_number' => "#R{$transaction->id}",
                    'Order_number' => $transaction->order_id ? "#ORD-{$transaction->order_id}" : null,
                    'Process_date' => date('d-m-Y', strtotime($transaction->created_at)),
                    'Total_money' => (float) $transaction->amount,
                    'process_type' => $transaction->type === 'credit' ? 'deposit' : 'pay',
                    'Payment_method' => $transaction->payment_method ?? 'cash',
                ];
            });

        // Calculate wallet statistics
        $totalDeposits = DB::table('wallet_transactions')
            ->where('client_id', $client->id)
            ->where('type', 'credit')
            ->sum('amount');

        $totalWithdrawals = DB::table('wallet_transactions')
            ->where('client_id', $client->id)
            ->where('type', 'debit')
            ->sum('amount');

        // Get payment cards
        $bankCards = DB::table('payment_cards')
            ->where('client_id', $client->id)
            ->get()
            ->map(function ($card) {
                // Mask card number
                $cardNumber = $card->card_number;
                $maskedNumber = substr($cardNumber, 0, 4).'..'.substr($cardNumber, -3);

                // Check if card is expired
                $expiryDate = $card->expiry_date;
                $isExpired = false;
                if ($expiryDate) {
                    $parts = explode('/', $expiryDate);
                    if (count($parts) === 2) {
                        $expiryMonth = (int) $parts[0];
                        $expiryYear = 2000 + (int) $parts[1];
                        $expiryTimestamp = mktime(0, 0, 0, $expiryMonth + 1, 0, $expiryYear);
                        $isExpired = $expiryTimestamp < time();
                    }
                }

                return [
                    'id' => $card->id,
                    'Card_symbol' => strtolower($card->card_type),
                    'Card_number' => $maskedNumber,
                    'Cvv' => '***', // Never expose CVV
                    'Expiry_date' => $card->expiry_date,
                    'Adding_card_date' => date('d-m-Y', strtotime($card->created_at)),
                    'Status' => $isExpired ? 'expired' : 'active',
                ];
            });

        // Get full_name as string
        $fullName = $client->full_name;
        if (is_array($fullName)) {
            $fullName = $fullName['ar'] ?? $fullName['en'] ?? '';
        }

        $response = [
            'id' => $client->id,
            'full_name' => $fullName,
            'email' => $client->email,
            'phone' => $client->phone,
            'image' => $client->image,
            'is_verified' => $client->is_verified,
            'is_active' => $client->is_active ?? true,
            'is_banned' => $client->is_banned ?? false,
            'ban_reason' => $client->ban_reason,
            'banned_at' => $client->banned_at?->toDateTimeString(),
            'addresses' => $client->addresses->map(function ($address) {
                return [
                    'id' => $address->id,
                    'title' => $address->title,
                    'national_address' => $address->national_address,
                    'street_name' => $address->street_name,
                    'building_number' => $address->building_number,
                    'street_number' => $address->street_number,
                    'floor' => $address->floor,
                    'apartment' => $address->apartment,
                    'latitude' => $address->latitude,
                    'longitude' => $address->longitude,
                    'notes' => $address->notes,
                    'is_default' => $address->is_default,
                    'zone_id' => $address->zone_id,
                ];
            }),
            'Orders' => $orders,
            'Wallet' => [
                'Statistics' => [
                    'Current_balance' => (float) $walletBalance,
                    'Total_delivery' => (float) $totalWithdrawals,
                    'Order_balance' => (float) ($totalDeposits - $totalWithdrawals),
                ],
                'Financials' => $walletTransactions,
            ],
            'Client_bank_cards' => $bankCards,
        ];

        return successResponse(
            $response,
            __('admin::client.client_retrieved')
        );
    }
}
