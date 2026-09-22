<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\UploadFilesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Order\Models\Order;
use Modules\Payment\Models\PaymentTransaction;
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

        // 2. Financial transactions (per-order payments) for this laundry - the "المعاملات المالية"
        // table. Despite the key name (kept for frontend compatibility) this is not withdrawal
        // history: it's every payment transaction on an order handled by one of this vendor's
        // branches, which is what the table's columns (branch, order number, payment method) need.
        $withdrawalOrdersLogPaginator = PaymentTransaction::with(['order.branch'])
            ->whereHas('order', fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 15));

        $withdrawalOrdersLogPaginator->getCollection()->transform(function ($transaction) use ($lang) {
            $order = $transaction->order;
            $processedAt = $transaction->paid_at ?? $transaction->created_at;

            return [
                'process_number' => $transaction->transaction_id,
                'client_name' => $order?->branch ? $order->branch->getTranslation('name', $lang) : null,
                'request_number' => $order?->order_number,
                'process_date' => $processedAt ? $processedAt->format('Y-m-d') : null,
                'total_price' => (float) $transaction->amount,
                'payment_image' => $transaction->payment_method,
                // Needed by the actions column: GET/POST /orders/{order_id}/payments*
                // take the numeric order id, not the order_number string above.
                'order_id' => $order?->id,
            ];
        });

        // 3. Withdrawal requests for this vendor, joined to the bank account used.
        // Field names match exactly what WalletTab.tsx reads (request.bank_name,
        // request.iban, request.amount, request.created_at) - the previous shape
        // (Iban/Amount/Date, no bank_name at all) never matched, it just went
        // unnoticed because this table has had zero rows in it.
        $withdrawalRequestsQuery = DB::table('vendor_withdrawal_requests')
            ->where('vendor_withdrawal_requests.vendor_id', $vendorId)
            ->join('vendor_bank_accounts', 'vendor_withdrawal_requests.bank_account_id', '=', 'vendor_bank_accounts.id')
            ->select(
                'vendor_withdrawal_requests.id',
                'vendor_withdrawal_requests.status',
                'vendor_withdrawal_requests.amount',
                'vendor_withdrawal_requests.created_at',
                'vendor_bank_accounts.bank_name',
                'vendor_bank_accounts.iban_number',
                'vendor_bank_accounts.account_holder'
            )
            ->orderBy('vendor_withdrawal_requests.created_at', 'desc');

        $mapWithdrawalRequest = fn ($request) => [
            'id' => $request->id,
            'bank_name' => $request->bank_name,
            'iban' => $request->iban_number,
            'account_holder' => $request->account_holder,
            'amount' => (float) $request->amount,
            'created_at' => $request->created_at,
            'status' => $request->status,
        ];

        // "طلبات السحب المعلّقة" - pending only, for the actionable accept/reject list.
        $temporaryWithdrawalRequests = (clone $withdrawalRequestsQuery)
            ->where('vendor_withdrawal_requests.status', 'pending')
            ->get()
            ->map($mapWithdrawalRequest);

        // "سجل طلبات السحب" - full history regardless of status.
        $withdrawalRequestsLog = $withdrawalRequestsQuery
            ->limit(50)
            ->get()
            ->map($mapWithdrawalRequest);

        return successResponse([
            'States' => $states,
            // Plain array, not the paginator object - the frontend does
            // `walletData.withdrawal_orders_log || []` and passes it straight into
            // a table as `data`, so an object (current_page, data, ...) renders as empty.
            'withdrawal_orders_log' => $withdrawalOrdersLogPaginator->items(),
            'Temporary_withdrawal_requests' => $temporaryWithdrawalRequests,
            'Withdrawal_requests_log' => $withdrawalRequestsLog,
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

}
