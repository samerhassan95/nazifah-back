<?php

namespace Modules\Vendor\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class WalletController extends Controller
{
    /**
     * Get wallet details
     */
    public function index(Request $request): JsonResponse
    {
        $vendor = $request->user();

        // Get wallet balance (assuming vendors table has wallet_balance column)
        $balance = $vendor->wallet_balance ?? 0;

        // Get transactions from vendor_wallet_transactions table
        $transactions = DB::table('vendor_wallet_transactions')
            ->where('vendor_id', $vendor->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($txn) {
                return [
                    'Txn_id' => $txn->id,
                    'Amount' => (float) $txn->amount,
                    'Date' => $txn->created_at,
                    'Payment_method' => $txn->payment_method ?? 'N/A',
                ];
            });

        // Get withdrawal requests
        $withdrawalRequests = DB::table('vendor_withdrawal_requests')
            ->where('vendor_id', $vendor->id)
            ->whereIn('status', ['pending', 'approved'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($request) {
                return [
                    'requests_id' => $request->id,
                    'Amount' => (float) $request->amount,
                    'Date' => $request->created_at,
                    'status' => $request->status,
                ];
            });

        return successResponse([
            'Amount_balance' => (float) $balance,
            'Transactions' => $transactions,
            'Withdrawal_requests' => $withdrawalRequests,
        ], __('vendor.wallet_details_retrieved'));
    }

    /**
     * Add withdrawal request
     */
    public function addWithdrawalRequest(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount' => ['required', 'numeric', 'min:1'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $vendor = $request->user();
        $balance = $vendor->wallet_balance ?? 0;

        if ($request->amount > $balance) {
            return errorResponse(__('vendor.insufficient_balance'), null, 400);
        }

        $withdrawalRequest = DB::table('vendor_withdrawal_requests')->insertGetId([
            'vendor_id' => $vendor->id,
            'amount' => $request->amount,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return successResponse([
            'requests_id' => $withdrawalRequest,
            'amount' => (float) $request->amount,
            'status' => 'pending',
        ], __('vendor.withdrawal_request_created'), 201);
    }
}
