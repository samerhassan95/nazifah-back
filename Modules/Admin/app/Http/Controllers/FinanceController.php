<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Order\Models\Order;
use Modules\Payment\Models\PaymentTransaction;

class FinanceController extends Controller
{
    /**
     * Get finance overview
     * GET /finance/overview
     */
    public function overview(Request $request): JsonResponse
    {
        // Calculate stats
        $totalRevenue = PaymentTransaction::where('status', 'completed')->sum('amount');
        $totalWithdrawals = DB::table('vendor_withdrawal_requests')
            ->where('status', 'completed')
            ->sum('amount');
        $netProfit = $totalRevenue - $totalWithdrawals;
        $currentBalance = $totalRevenue - $totalWithdrawals;

        // Monthly revenue (January to December)
        $monthlyRevenue = [];
        $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        $currentYear = now()->year;

        for ($i = 1; $i <= 12; $i++) {
            $monthStart = Carbon::create($currentYear, $i, 1)->startOfMonth();
            $monthEnd = Carbon::create($currentYear, $i, 1)->endOfMonth();

            $revenue = PaymentTransaction::where('status', 'completed')
                ->whereBetween('created_at', [$monthStart, $monthEnd])
                ->sum('amount');

            $monthlyRevenue[] = [
                'month' => $months[$i - 1],
                'revenue' => (float) $revenue,
            ];
        }

        // Platform revenue (total revenue from all completed payments)
        $platformRevenue = (float) $totalRevenue;

        // Withdrawn details (total completed withdrawals)
        $withdrawnDetails = (float) $totalWithdrawals;

        // System profits (platform revenue - withdrawn)
        $systemProfits = (float) $netProfit;

        // Revenue by payment method (only 3 keys: نقدي, إلكتروني, محفظة)
        $revenueByPaymentMethod = [
            [
                'method' => 'نقدي',
                'value' => (float) PaymentTransaction::where('status', 'completed')
                    ->where('payment_method', 'cash_on_delivery')
                    ->sum('amount'),
            ],
            [
                'method' => 'إلكتروني',
                'value' => (float) PaymentTransaction::where('status', 'completed')
                    ->whereIn('payment_method', ['visa', 'mastercard', 'mada', 'stc_pay', 'apple_pay', 'google_pay', 'samsung_pay'])
                    ->sum('amount'),
            ],
            [
                'method' => 'محفظة',
                'value' => (float) PaymentTransaction::where('status', 'completed')
                    ->where('payment_method', 'nazefah_wallet')
                    ->sum('amount'),
            ],
        ];

        // Revenue by city (top 4 cities)
        $revenueByCity = DB::table('orders')
            ->join('branches', 'orders.branch_id', '=', 'branches.id')
            ->join('zones', 'branches.zone_id', '=', 'zones.id')
            ->join('payment_transactions', 'orders.id', '=', 'payment_transactions.order_id')
            ->where('payment_transactions.status', 'completed')
            ->select(
                'zones.name',
                DB::raw('SUM(payment_transactions.amount) as total_revenue'),
                DB::raw('COUNT(DISTINCT orders.id) as order_count')
            )
            ->groupBy('zones.id', 'zones.name')
            ->orderByDesc('total_revenue')
            ->limit(4)
            ->get()
            ->map(function ($item) use ($totalRevenue) {
                $name = is_string($item->name) ? json_decode($item->name, true) : $item->name;
                $cityName = is_array($name) ? ($name['ar'] ?? $name['en'] ?? 'Unknown') : $name;

                return [
                    'city' => $cityName,
                    'percentage' => $totalRevenue > 0 ? round(($item->total_revenue / $totalRevenue) * 100, 2) : 0,
                    'value' => (float) $item->total_revenue,
                ];
            })
            ->toArray();

        // Today's activities (recent orders)
        $todayActivities = Order::whereDate('created_at', today())
            ->with(['client', 'branch'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($order) {
                $clientName = $order->client ? ($order->client->name ?? 'Unknown') : 'Unknown';
                $time = Carbon::parse($order->created_at)->format('h:i A');

                return [
                    'id' => $order->id,
                    'title' => 'إنشاء طلب جديد',
                    'description' => "تم إنشاء طلب جديد #{$order->order_number} بواسطة العميل {$clientName}",
                    'time' => $time,
                ];
            })
            ->toArray();

        // Bank accounts (approved accounts)
        $bankAccounts = DB::table('vendor_bank_accounts')
            ->where('status', 'approved')
            ->join('vendors', 'vendor_bank_accounts.vendor_id', '=', 'vendors.id')
            ->select(
                'vendor_bank_accounts.id',
                'vendors.name as account_name',
                'vendor_bank_accounts.iban_number as iban',
                'vendor_bank_accounts.status',
                DB::raw("CASE
                    WHEN vendor_bank_accounts.status = 'approved' THEN 'موافق عليه'
                    WHEN vendor_bank_accounts.status = 'pending' THEN 'قيد الانتظار'
                    WHEN vendor_bank_accounts.status = 'rejected' THEN 'مرفوض'
                    ELSE 'غير معروف'
                END as status_label")
            )
            ->get()
            ->map(function ($account) {
                $name = is_string($account->account_name) ? json_decode($account->account_name, true) : $account->account_name;
                $accountName = is_array($name) ? ($name['ar'] ?? $name['en'] ?? 'Unknown') : $account->account_name;

                return [
                    'id' => $account->id,
                    'account_name' => $accountName,
                    'iban' => $account->iban,
                    'status' => $account->status,
                    'status_label' => $account->status_label,
                ];
            })
            ->toArray();

        // Top revenue laundries (top 6)
        $topRevenueLaundries = DB::table('orders')
            ->join('branches', 'orders.branch_id', '=', 'branches.id')
            ->join('vendors', 'branches.vendor_id', '=', 'vendors.id')
            ->join('payment_transactions', 'orders.id', '=', 'payment_transactions.order_id')
            ->where('payment_transactions.status', 'completed')
            ->select(
                'vendors.id',
                'vendors.name',
                DB::raw('SUM(payment_transactions.amount) as total_revenue')
            )
            ->groupBy('vendors.id', 'vendors.name')
            ->orderByDesc('total_revenue')
            ->limit(6)
            ->get()
            ->map(function ($vendor) {
                $name = is_string($vendor->name) ? json_decode($vendor->name, true) : $vendor->name;
                $vendorName = is_array($name) ? ($name['ar'] ?? $name['en'] ?? 'Unknown') : $vendor->name;

                return [
                    'name' => $vendorName,
                    'amount' => (float) $vendor->total_revenue,
                ];
            })
            ->toArray();

        // Bank accounts summary
        $pendingAccounts = DB::table('vendor_bank_accounts')
            ->where('status', 'pending')
            ->count();
        $rejectedAccounts = DB::table('vendor_bank_accounts')
            ->where('status', 'rejected')
            ->count();

        $bankAccountsSummary = [
            'total_amount' => $pendingAccounts + $rejectedAccounts,
            'details' => [
                [
                    'label' => 'بانتظار التفعيل',
                    'value' => $pendingAccounts,
                ],
                [
                    'label' => 'طلبات مرفوضة',
                    'value' => $rejectedAccounts,
                ],
            ],
        ];

        return successResponse([
            'stats' => [
                'total_revenue' => (float) $totalRevenue,
                'net_profit' => (float) $netProfit,
                'current_balance' => (float) $currentBalance,
                'withdrawn_funds' => (float) $totalWithdrawals,
            ],
            'revenue_details' => [
                'monthly_revenue' => $monthlyRevenue,
                'platform_revenue' => $platformRevenue,
                'withdrawn_details' => $withdrawnDetails,
                'system_profits' => $systemProfits,
            ],
            'revenue_by_payment_method' => $revenueByPaymentMethod,
            'revenue_by_city' => $revenueByCity,
            'today_activities' => $todayActivities,
            'bank_accounts' => $bankAccounts,
            'top_revenue_laundries' => $topRevenueLaundries,
            'bank_accounts_summary' => $bankAccountsSummary,
        ], 'Finance overview retrieved successfully');
    }

    /**
     * Get wallet details
     * GET /finance/wallet
     */
    public function wallet(Request $request): JsonResponse
    {
        // Get all transactions
        $transactions = DB::table('payment_transactions')
            ->join('orders', 'payment_transactions.order_id', '=', 'orders.id')
            ->join('clients', 'orders.client_id', '=', 'clients.id')
            ->join('branches', 'orders.branch_id', '=', 'branches.id')
            ->join('vendors', 'branches.vendor_id', '=', 'vendors.id')
            ->select(
                'payment_transactions.id',
                'payment_transactions.transaction_id as transaction_number',
                'clients.full_name as customer_name',
                'orders.order_number',
                'branches.name',
                'vendors.name as vendor_name',
                'orders.created_at as transaction_date',
                'payment_transactions.amount as total_amount',
                'payment_transactions.payment_method'
            )
            ->where('payment_transactions.status', 'completed')
            ->orderBy('payment_transactions.created_at', 'desc')
            ->limit(50)
            ->get()
            ->map(function ($transaction) {
                $customerName = is_string($transaction->customer_name)
                    ? json_decode($transaction->customer_name, true)
                    : $transaction->customer_name;
                $customerName = is_array($customerName)
                    ? ($customerName['ar'] ?? $customerName['en'] ?? 'Unknown')
                    : $transaction->customer_name;

                $branchName = is_string($transaction->name)
                    ? json_decode($transaction->name, true)
                    : $transaction->name;
                $branchName = is_array($branchName)
                    ? ($branchName['ar'] ?? $branchName['en'] ?? 'Unknown')
                    : $transaction->name;

                $vendorName = is_string($transaction->vendor_name)
                    ? json_decode($transaction->vendor_name, true)
                    : $transaction->vendor_name;
                $vendorName = is_array($vendorName)
                    ? ($vendorName['ar'] ?? $vendorName['en'] ?? 'Unknown')
                    : $transaction->vendor_name;

                $paymentMethod = $this->mapPaymentMethod($transaction->payment_method);

                return [
                    'id' => $transaction->id,
                    'transaction_number' => '#'.$transaction->transaction_number,
                    'customer_name' => $customerName,
                    'order_number' => '#'.$transaction->order_number,
                    'laundry_branch' => $vendorName.' - '.$branchName,
                    'transaction_date' => Carbon::parse($transaction->transaction_date)->format('Y-m-d'),
                    'total_amount' => [
                        'value' => (float) $transaction->total_amount,
                    ],
                    'payment_method' => [
                        'type' => $paymentMethod,
                    ],
                ];
            })
            ->toArray();

        // Withdrawal stats
        $totalWithdrawals = DB::table('vendor_withdrawal_requests')
            ->where('status', 'completed')
            ->sum('amount');

        $completedRequests = DB::table('vendor_withdrawal_requests')
            ->where('status', 'completed')
            ->count();

        $underReviewRequests = DB::table('vendor_withdrawal_requests')
            ->where('status', 'pending')
            ->count();

        $rejectedRequests = DB::table('vendor_withdrawal_requests')
            ->where('status', 'rejected')
            ->count();

        // Pending withdrawal requests
        $pendingWithdrawalRequests = DB::table('vendor_withdrawal_requests')
            ->join('vendor_bank_accounts', 'vendor_withdrawal_requests.bank_account_id', '=', 'vendor_bank_accounts.id')
            ->join('vendors', 'vendor_withdrawal_requests.vendor_id', '=', 'vendors.id')
            ->where('vendor_withdrawal_requests.status', 'pending')
            ->select(
                'vendor_withdrawal_requests.id',
                'vendors.name as entity_name',
                'vendor_bank_accounts.iban_number as iban',
                'vendor_withdrawal_requests.amount',
                'vendor_withdrawal_requests.created_at as request_date'
            )
            ->orderBy('vendor_withdrawal_requests.created_at', 'desc')
            ->get()
            ->map(function ($request) {
                $entityName = is_string($request->entity_name)
                    ? json_decode($request->entity_name, true)
                    : $request->entity_name;
                $entityName = is_array($entityName)
                    ? ($entityName['ar'] ?? $entityName['en'] ?? 'Unknown')
                    : $request->entity_name;

                return [
                    'id' => $request->id,
                    'entity_name' => $entityName,
                    'iban' => $request->iban,
                    'amount' => (float) $request->amount,
                    'currency' => 'ريال سعودي',
                    'request_date' => Carbon::parse($request->request_date)->format('Y-m-d'),
                    'actions' => ['accept', 'reject'],
                ];
            })
            ->toArray();

        // Withdrawal history log
        $withdrawalHistoryLog = DB::table('vendor_withdrawal_requests')
            ->join('vendor_bank_accounts', 'vendor_withdrawal_requests.bank_account_id', '=', 'vendor_bank_accounts.id')
            ->whereIn('vendor_withdrawal_requests.status', ['completed', 'rejected', 'pending'])
            ->select(
                'vendor_withdrawal_requests.id',
                'vendor_withdrawal_requests.amount',
                'vendor_withdrawal_requests.status',
                'vendor_withdrawal_requests.created_at as date'
            )
            ->orderBy('vendor_withdrawal_requests.created_at', 'desc')
            ->limit(50)
            ->get()
            ->map(function ($log) {
                $statusLabels = [
                    'completed' => 'مكتملة',
                    'rejected' => 'مرفوضة',
                    'pending' => 'قيد الانتظار',
                ];

                return [
                    'id' => $log->id,
                    'amount' => (float) $log->amount,
                    'currency' => 'ريال سعودي',
                    'date' => Carbon::parse($log->date)->format('Y-m-d'),
                    'status' => $log->status,
                    'status_label' => $statusLabels[$log->status] ?? 'غير معروف',
                ];
            })
            ->toArray();

        return successResponse([
            'transactions' => $transactions,
            'withdrawal_stats' => [
                'total_withdrawals' => [
                    'value' => (float) $totalWithdrawals,
                    'currency' => 'ر.س',
                ],
                'completed_requests' => $completedRequests,
                'under_review_requests' => $underReviewRequests,
                'rejected_requests' => $rejectedRequests,
            ],
            'pending_withdrawal_requests' => $pendingWithdrawalRequests,
            'withdrawal_history_log' => $withdrawalHistoryLog,
        ], 'Wallet details retrieved successfully');
    }

    /**
     * Get bank account details
     * GET /finance/bank_account
     */
    public function bankAccount(Request $request): JsonResponse
    {
        // Stats
        $totalAccounts = DB::table('vendor_bank_accounts')->count();
        $pendingApproval = DB::table('vendor_bank_accounts')
            ->where('status', 'pending')
            ->count();
        $rejectedAccounts = DB::table('vendor_bank_accounts')
            ->where('status', 'rejected')
            ->count();

        // Bank account requests
        $bankAccountRequests = DB::table('vendor_bank_accounts')
            ->join('vendors', 'vendor_bank_accounts.vendor_id', '=', 'vendors.id')
            ->whereIn('vendor_bank_accounts.status', ['pending', 'rejected'])
            ->select(
                'vendor_bank_accounts.id',
                'vendors.name as entity_name',
                'vendor_bank_accounts.iban_number as iban',
                'vendor_bank_accounts.created_at as request_date',
                'vendor_bank_accounts.status',
                // Corrected Logic: If no APPROVED account exists for this vendor_id, it is a 'create'
                DB::raw("CASE
                WHEN NOT EXISTS (
                    SELECT 1 FROM vendor_bank_accounts as approved_acc
                    WHERE approved_acc.vendor_id = vendor_bank_accounts.vendor_id
                    AND approved_acc.status = 'approved'
                ) THEN 'create'
                ELSE 'update'
            END as request_type")
            )
            ->orderBy('vendor_bank_accounts.created_at', 'desc')
            ->get()
            ->map(function ($request) {
                $entityName = is_string($request->entity_name)
                    ? json_decode($request->entity_name, true)
                    : $request->entity_name;

                $entityName = is_array($entityName)
                    ? ($entityName['ar'] ?? $entityName['en'] ?? 'Unknown')
                    : $request->entity_name;

                $requestTypeLabels = [
                    'create' => 'إضافة',
                    'update' => 'تعديل',
                ];

                return [
                    'id' => $request->id,
                    'entity_name' => $entityName,
                    'iban' => $request->iban,
                    'request_date' => \Carbon\Carbon::parse($request->request_date)->format('Y-m-d'),
                    'request_type' => $request->request_type,
                    'request_type_label' => $requestTypeLabels[$request->request_type] ?? 'غير معروف',
                    'status' => $request->status,
                ];
            });

        // Bank accounts history (Approved/Active accounts)
        $bankAccountsHistory = DB::table('vendor_bank_accounts')
            ->join('vendors', 'vendor_bank_accounts.vendor_id', '=', 'vendors.id')
            ->select(
                'vendor_bank_accounts.id',
                'vendors.name as vendor_name',
                'vendor_bank_accounts.bank_name',
                'vendor_bank_accounts.iban_number as iban',
                'vendor_bank_accounts.status'
            )
            ->orderBy('vendor_bank_accounts.created_at', 'desc')
            ->limit(50)
            ->get()
            ->map(function ($account) {
                $vendorName = is_string($account->vendor_name)
                    ? json_decode($account->vendor_name, true)
                    : $account->vendor_name;
                $vendorName = is_array($vendorName)
                    ? ($vendorName['ar'] ?? $vendorName['en'] ?? 'Unknown')
                    : $account->vendor_name;

                $statusLabels = [
                    'completed' => 'مكتملة',
                    'rejected' => 'مرفوضة',
                    'pending' => 'قيد الانتظار',
                    'approved' => 'موافق عليه',
                ];

                return [
                    'id' => $account->id,
                    'vendor_name' => $vendorName,
                    'bank_name' => $account->bank_name,
                    'iban' => $account->iban,
                    'status' => $account->status === 'approved' ? 'completed' : $account->status,
                    'status_label' => $statusLabels[$account->status] ?? 'غير معروف',
                ];
            });

        return successResponse([
            'stats' => [
                'total_accounts' => $totalAccounts,
                'pending_approval' => $pendingApproval,
                'rejected_accounts' => $rejectedAccounts,
            ],
            'bank_account_requests' => $bankAccountRequests,
            'bank_accounts_history' => $bankAccountsHistory,
        ], 'Bank account details retrieved successfully');
    }

    /**
     * Approve finance request (withdrawal or bank account)
     * POST /finance/{id}/approve
     *
     * Query parameter: type=withdrawal or type=bank_account
     */
    public function approve(Request $request, $id): JsonResponse
    {
        $type = $request->input('type', 'withdrawal'); // 'withdrawal' or 'bank_account'

        if ($type === 'withdrawal') {
            $withdrawal = DB::table('vendor_withdrawal_requests')->where('id', $id)->first();

            if (! $withdrawal) {
                return notFoundResponse('Withdrawal request not found');
            }

            if ($withdrawal->status !== 'pending') {
                return errorResponse('Withdrawal request is not pending', null, 400);
            }

            DB::table('vendor_withdrawal_requests')
                ->where('id', $id)
                ->update([
                    'status' => 'approved',
                    'processed_at' => now(),
                    'updated_at' => now(),
                ]);

            return successResponse([
                'id' => $id,
                'status' => 'approved',
                'message' => 'Withdrawal request approved successfully',
            ], 'Withdrawal request approved successfully');
        } else {
            $bankAccount = DB::table('vendor_bank_accounts')->where('id', $id)->first();

            if (! $bankAccount) {
                return notFoundResponse('Bank account not found');
            }

            if ($bankAccount->status === 'approved') {
                return errorResponse('Bank account is already approved', null, 400);
            }

            DB::table('vendor_bank_accounts')
                ->where('id', $id)
                ->update([
                    'status' => 'approved',
                    'updated_at' => now(),
                ]);

            return successResponse([
                'id' => $id,
                'status' => 'approved',
                'message' => 'Bank account approved successfully',
            ], 'Bank account approved successfully');
        }
    }

    /**
     * Reject a finance request
     * POST /finance/{id}/reject
     */
    public function reject(Request $request, $id): JsonResponse
    {
        $type = $request->input('type', 'withdrawal');
        $reason = $request->input('rejection_reason', 'تم الرفض بواسطة الإدارة');

        if ($type === 'withdrawal') {
            $updated = DB::table('vendor_withdrawal_requests')
                ->where('id', $id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'rejected',
                    'updated_at' => now(),
                ]);

            if (! $updated) {
                return errorResponse('Request not found or already processed');
            }

            return successResponse(null, 'Withdrawal request rejected');
        } else {
            $updated = DB::table('vendor_bank_accounts')
                ->where('id', $id)
                ->update([
                    'status' => 'rejected',
                    'rejection_reason' => $reason,
                    'updated_at' => now(),
                ]);

            return successResponse(null, 'Bank account rejected');
        }
    }

    /**
     * Get all withdrawal requests
     * GET /finance/withdrawals?status=pending
     */
    /**
     * Get all withdrawal requests
     * GET /finance/withdrawals?status=pending
     */
    public function withdrawals(Request $request): JsonResponse
    {
        $status = $request->input('status');
        $perPage = $request->input('per_page', 15);
        $lang = app()->getLocale();

        $query = DB::table('vendor_withdrawal_requests')
            ->join('vendors', 'vendor_withdrawal_requests.vendor_id', '=', 'vendors.id')
            ->join('vendor_bank_accounts', 'vendor_withdrawal_requests.bank_account_id', '=', 'vendor_bank_accounts.id')
            ->select(
                'vendor_withdrawal_requests.*',
                'vendors.name as vendor_name',
                'vendors.logo as vendor_logo',
                'vendor_bank_accounts.bank_name',
                'vendor_bank_accounts.iban_number',
                'vendor_bank_accounts.account_holder'
            );

        if ($status) {
            $query->where('vendor_withdrawal_requests.status', $status);
        }

        // 1. Get the paginator
        $withdrawals = $query->orderBy('vendor_withdrawal_requests.created_at', 'desc')
            ->paginate($perPage);

        // 2. Transform the collection inside the paginator
        $transformedItems = collect($withdrawals->items())->map(function ($item) use ($lang) {
            $name = is_string($item->vendor_name) ? json_decode($item->vendor_name, true) : $item->vendor_name;
            $vendorName = is_array($name) ? ($name[$lang] ?? $name['ar'] ?? $name['en'] ?? 'Unknown') : $item->vendor_name;

            return [
                'id' => $item->id,
                'vendor' => [
                    'id' => $item->vendor_id,
                    'name' => $vendorName,
                    'logo' => $item->vendor_logo ? (str_starts_with($item->vendor_logo, 'http') ? $item->vendor_logo : config('app.url').$item->vendor_logo) : null,
                ],
                'bank_details' => [
                    'bank_name' => $item->bank_name,
                    'iban' => $item->iban_number,
                    'account_holder' => $item->account_holder,
                ],
                'amount' => (float) $item->amount,
                'status' => $item->status,
                'status_label' => $this->mapStatus($item->status),
                'request_date' => \Carbon\Carbon::parse($item->created_at)->format('Y-m-d H:i'),
                'processed_at' => $item->processed_at ? \Carbon\Carbon::parse($item->processed_at)->format('Y-m-d H:i') : null,
            ];
        });

        // 3. Update the paginator's collection with transformed data
        $withdrawals->setCollection($transformedItems);

        // 4. Use successResponse. It will automatically detect $withdrawals is a Paginator
        // and move the pagination info to the 'meta' key.
        return successResponse($withdrawals, 'Withdrawal requests retrieved successfully');
    }

    /**
     * Helper to map status to Arabic labels
     */
    private function mapStatus($status): string
    {
        return [
            'pending' => 'قيد الانتظار',
            'approved' => 'موافق عليه',
            'completed' => 'مكتمل',
            'rejected' => 'مرفوض',
        ][$status] ?? 'غير معروف';
    }

    /**
     * Map payment method to display format
     */
    private function mapPaymentMethod($method): string
    {
        $mapping = [
            'cash_on_delivery' => 'نقدي',
            'visa' => 'VISA',
            'mastercard' => 'MASTERCARD',
            'mada' => 'MADA',
            'nazefah_wallet' => 'محفظة',
            'stc_pay' => 'STC_PAY',
            'samsung_pay' => 'SAMSUNG_PAY',
            'apple_pay' => 'APPLE_PAY',
            'google_pay' => 'GOOGLE_PAY',
        ];

        return $mapping[$method] ?? strtoupper($method);
    }
}
