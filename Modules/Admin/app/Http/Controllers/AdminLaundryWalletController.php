<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\UploadFilesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Order\Models\Order;
use Modules\Vendor\Models\Vendor;

class AdminLaundryWalletController extends Controller
{
    protected $uploadFilesService;

    public function __construct(UploadFilesService $uploadFilesService)
    {
        $this->uploadFilesService = $uploadFilesService;
    }

    /**
     * Get wallet details for a laundry
     * GET /laundries/wallet
     */
    public function index(Request $request): JsonResponse
    {
        $vendorId = $request->input('vendor_id');
        $lang = app()->getLocale();

        if (! $vendorId) {
            return errorResponse('Vendor ID is required', null, 400);
        }

        $vendor = Vendor::with(['branches'])->find($vendorId);

        if (! $vendor) {
            return notFoundResponse('Vendor not found');
        }

        $branchIds = $vendor->branches->pluck('id');

        // 1. Calculate Wallet States
        $totalRevenue = Order::whereIn('branch_id', $branchIds)
            ->whereHas('paymentTransactions', function ($q) {
                $q->where('status', 'completed');
            })
            ->sum('final_amount');

        $totalWithdrawal = DB::table('vendor_withdrawal_requests')
            ->where('vendor_id', $vendorId)
            ->where('status', 'completed')
            ->sum('amount');

        $withdrawalAvailable = ($vendor->wallet_balance ?? 0);

        $states = [
            'vendor_id' => $vendor->id,
            'vendor_name' => $vendor->getTranslation('name', $lang),
            'Total_revenue' => (float) $totalRevenue,
            'Withdrawal_available' => (float) $withdrawalAvailable,
            'Total_withdrawal' => (float) $totalWithdrawal,
        ];

        // 2. Get withdrawal orders log (Paginated)
        // Fix: Use paginate() first, then map the items inside the lengthAwarePaginator
        $withdrawalOrdersLogPaginator = DB::table('vendor_withdrawal_requests')
            ->where('vendor_id', $vendorId)
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 15));

        // Transform the paginated items
        $withdrawalOrdersLogPaginator->getCollection()->transform(function ($request) use ($vendor) {
            return [
                'Order_logo' => $vendor->logo ? (str_starts_with($vendor->logo, 'http') ? $vendor->logo : config('app.url').$vendor->logo) : null,
                'Amount' => (float) $request->amount,
                'Date' => $request->created_at ? date('d M Y', strtotime($request->created_at)) : null,
                'Status' => $this->mapWithdrawalStatus($request->status),
            ];
        });

        // 3. Get temporary withdrawal requests (Pending)
        // Fix: Added ->get() before ->map()
        $temporaryWithdrawalRequests = DB::table('vendor_withdrawal_requests')
            ->where('vendor_withdrawal_requests.vendor_id', $vendorId)
            ->where('vendor_withdrawal_requests.status', 'pending')
            ->join('vendor_bank_accounts', 'vendor_withdrawal_requests.bank_account_id', '=', 'vendor_bank_accounts.id')
            ->select(
                'vendor_withdrawal_requests.*',
                'vendor_bank_accounts.bank_name',
                'vendor_bank_accounts.iban_number',
                'vendor_bank_accounts.account_holder'
            )
            ->orderBy('vendor_withdrawal_requests.created_at', 'desc')
            ->get() // <--- Crucial fix here
            ->map(function ($request) use ($vendor, $lang) {
                return [
                    'Logo' => $vendor->logo ? (str_starts_with($vendor->logo, 'http') ? $vendor->logo : config('app.url').$vendor->logo) : null,
                    'laundry_name' => $vendor->getTranslation('name', $lang),
                    'Iban' => $request->iban_number,
                    'Amount' => (float) $request->amount,
                    'Date' => $request->created_at ? date('d M Y', strtotime($request->created_at)) : null,
                ];
            });

        return successResponse([
            'States' => $states,
            'withdrawal_orders_log' => $withdrawalOrdersLogPaginator,
            'Temporary_withdrawal_requests' => $temporaryWithdrawalRequests,
        ], 'Wallet details retrieved successfully');
    }

    /**
     * Accept withdrawal request
     * POST /laundries/wallet/is_accepted
     */
    public function acceptWithdrawal(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vendor_id' => 'required|exists:vendors,id',
            'withdrawal_request_id' => 'required|exists:vendor_withdrawal_requests,id',
        ]);

        $withdrawalRequest = DB::table('vendor_withdrawal_requests')
            ->where('id', $validated['withdrawal_request_id'])
            ->where('vendor_id', $validated['vendor_id'])
            ->first();

        if (! $withdrawalRequest) {
            return notFoundResponse('Withdrawal request not found');
        }

        if ($withdrawalRequest->status !== 'pending') {
            return errorResponse('Withdrawal request is not pending', null, 400);
        }

        DB::table('vendor_withdrawal_requests')
            ->where('id', $validated['withdrawal_request_id'])
            ->update([
                'status' => 'approved',
                'processed_at' => now(),
            ]);

        return successResponse(null, 'Withdrawal request accepted successfully');
    }

    /**
     * Reject withdrawal request
     * POST /laundries/wallet/is_rejected
     */
    public function rejectWithdrawal(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vendor_id' => 'required|exists:vendors,id',
            'withdrawal_request_id' => 'required|exists:vendor_withdrawal_requests,id',
            'rejection_reason' => 'nullable|string',
        ]);

        $withdrawalRequest = DB::table('vendor_withdrawal_requests')
            ->where('id', $validated['withdrawal_request_id'])
            ->where('vendor_id', $validated['vendor_id'])
            ->first();

        if (! $withdrawalRequest) {
            return notFoundResponse('Withdrawal request not found');
        }

        if ($withdrawalRequest->status !== 'pending') {
            return errorResponse('Withdrawal request is not pending', null, 400);
        }

        DB::table('vendor_withdrawal_requests')
            ->where('id', $validated['withdrawal_request_id'])
            ->update([
                'status' => 'rejected',
                'rejection_reason' => $validated['rejection_reason'] ?? null,
                'processed_at' => now(),
            ]);

        return successResponse(null, 'Withdrawal request rejected successfully');
    }

    /**
     * Format amount with K suffix
     */
    private function formatAmount($amount): string
    {
        if ($amount >= 1000) {
            return number_format($amount / 1000, 0).'K';
        }

        return (string) $amount;
    }

    /**
     * Map withdrawal status
     */
    private function mapWithdrawalStatus($status): string
    {
        $statusMap = [
            'pending' => 'pending',
            'approved' => 'completed',
            'completed' => 'completed',
            'rejected' => 'rejected',
        ];

        return $statusMap[$status] ?? $status;
    }
}
