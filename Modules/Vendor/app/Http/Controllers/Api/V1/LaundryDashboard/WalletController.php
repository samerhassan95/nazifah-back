<?php

namespace Modules\Vendor\Http\Controllers\Api\V1\LaundryDashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\Branch\Models\Branch;
use Modules\Payment\Models\PaymentTransaction;
use Modules\Vendor\Models\Vendor;
use Modules\Vendor\Support\LaundryDashboardResponse;

/**
 * Laundry Dashboard → Operations Management → Wallet
 */
class WalletController extends Controller
{
    use LaundryDashboardResponse;

    private const TRANSACTION_STATUSES = ['completed', 'pending', 'failed', 'refunded', 'all'];

    /**
     * GET /wallet/withdrawal-requests?status=pending
     * status: pending (default) | approved | rejected | completed | all
     */
    public function withdrawalRequests(Request $request): JsonResponse
    {
        $validator = $this->makeValidator($request->all(), [
            'status' => ['nullable', 'in:pending,approved,rejected,completed,all'],
        ]);
        if ($validator->fails()) {
            return $this->invalid($validator->errors());
        }

        $status = $request->input('status', 'pending');

        $query = DB::table('vendor_withdrawal_requests as w')
            ->leftJoin('vendor_bank_accounts as ba', 'ba.id', '=', 'w.bank_account_id')
            ->leftJoin('banks as b', 'b.id', '=', 'ba.bank_id')
            ->where('w.vendor_id', $this->vendorId())
            ->select([
                'w.id', 'w.amount', 'w.status', 'w.rejection_reason', 'w.created_at',
                'ba.id as bank_account_id', 'ba.iban_number', 'ba.account_holder',
                'ba.bank_name as bank_name_text', 'b.name as bank_name_json', 'b.logo as bank_logo',
            ])
            ->orderByDesc('w.created_at');

        if ($status !== 'all') {
            $query->where('w.status', $status);
        }

        $rows = $query->get()->map(function ($r) {
            $bankName = $r->bank_name_json ?: $r->bank_name_text;

            return [
                'id' => $r->id,
                'bank_account_id' => $r->bank_account_id,
                'bank_name' => $this->bilingual($bankName ?? ''),
                'bank_logo' => $r->bank_logo ? (str_starts_with($r->bank_logo, 'http') ? $r->bank_logo : config('app.url').$r->bank_logo) : null,
                'iban' => $r->iban_number,
                'account_holder' => $r->account_holder,
                'amount' => (float) $r->amount,
                'status' => $r->status,
                'rejection_reason' => $r->rejection_reason,
                'created_at' => substr((string) $r->created_at, 0, 10),
            ];
        });

        return $this->ok($rows, 'تم جلب طلبات السحب بنجاح', 'Withdrawal requests retrieved successfully');
    }

    /**
     * POST /wallet/withdrawal-requests   { bank_account_id, amount }
     */
    public function addWithdrawalRequest(Request $request): JsonResponse
    {
        $validator = $this->makeValidator($request->all(), [
            'bank_account_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:1'],
        ]);
        if ($validator->fails()) {
            return $this->invalid($validator->errors());
        }

        $vendorId = $this->vendorId();

        $account = DB::table('vendor_bank_accounts')
            ->where('id', $request->bank_account_id)
            ->where('vendor_id', $vendorId)
            ->where('status', 'approved')
            ->first();
        if (! $account) {
            return $this->invalid(['bank_account_id' => [$this->t('الحساب البنكي غير موجود أو غير معتمد', 'Bank account not found or not approved')]]);
        }

        $balance = (float) (Vendor::find($vendorId)?->wallet_balance ?? 0);
        if ((float) $request->amount > $balance) {
            return $this->fail('الرصيد غير كافٍ', 'Insufficient balance', 400);
        }

        $id = DB::table('vendor_withdrawal_requests')->insertGetId([
            'vendor_id' => $vendorId,
            'bank_account_id' => $account->id,
            'amount' => $request->amount,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->ok(
            ['id' => $id, 'bank_account_id' => $account->id, 'amount' => (float) $request->amount, 'status' => 'pending'],
            'تم إنشاء طلب السحب بنجاح',
            'Withdrawal request created successfully',
            201
        );
    }

    /**
     * GET /wallet/transactions
     * Query: page, per_page, search, date_from, date_to, status (completed|pending|failed|refunded|all),
     *        sort_by (process_date|total_price), sort_dir (asc|desc)
     */
    public function transactions(Request $request): JsonResponse
    {
        $validator = $this->makeValidator($request->all(), [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:100'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'status' => ['nullable', 'in:'.implode(',', self::TRANSACTION_STATUSES)],
            'sort_by' => ['nullable', 'in:process_date,total_price'],
            'sort_dir' => ['nullable', 'in:asc,desc'],
        ]);
        if ($validator->fails()) {
            return $this->invalid($validator->errors());
        }

        $dateExpr = 'COALESCE(payment_transactions.paid_at, payment_transactions.created_at)';
        $query = $this->transactionsQuery()->with(['order.client']);

        $status = $request->input('status', 'completed');
        if ($status !== 'all') {
            $query->where('payment_transactions.status', $status);
        }
        if ($request->filled('date_from')) {
            $query->whereRaw("DATE($dateExpr) >= ?", [$request->date_from]);
        }
        if ($request->filled('date_to')) {
            $query->whereRaw("DATE($dateExpr) <= ?", [$request->date_to]);
        }
        if ($request->filled('search')) {
            $term = '%'.$request->search.'%';
            $query->where(function ($q) use ($term) {
                $q->where('payment_transactions.transaction_id', 'like', $term)
                    ->orWhere('payment_transactions.fort_id', 'like', $term)
                    ->orWhereHas('order', function ($o) use ($term) {
                        $o->where('order_number', 'like', $term)
                            ->orWhereHas('client', fn ($c) => $c->where('full_name', 'like', $term));
                    });
            });
        }

        $sortDir = $request->input('sort_dir', 'desc');
        if ($request->input('sort_by') === 'total_price') {
            $query->orderBy('payment_transactions.amount', $sortDir);
        } else {
            $query->orderByRaw("$dateExpr $sortDir");
        }
        $query->orderByDesc('payment_transactions.id');

        $paginator = $query->paginate((int) $request->input('per_page', 15));
        $items = collect($paginator->items())->map(fn ($t) => $this->formatTransaction($t));

        return $this->ok($items, 'تم جلب المعاملات المالية بنجاح', 'Transactions retrieved successfully', 200, $this->paginationMeta($paginator));
    }

    /** GET /wallet/transactions/{id} */
    public function transactionDetails(Request $request, int $id): JsonResponse
    {
        $transaction = $this->transactionsQuery()->with(['order.client'])->where('payment_transactions.id', $id)->first();
        if (! $transaction) {
            return $this->notFound('المعاملة غير موجودة', 'Transaction not found');
        }

        return $this->ok($this->formatTransaction($transaction), 'تم جلب المعاملة بنجاح', 'Transaction retrieved successfully');
    }

    private function transactionsQuery()
    {
        $branchIds = Branch::where('vendor_id', $this->vendorId())->pluck('id');

        return PaymentTransaction::query()
            ->select('payment_transactions.*')
            ->whereHas('order', fn ($q) => $q->whereIn('branch_id', $branchIds));
    }

    private function formatTransaction(PaymentTransaction $t): array
    {
        $order = $t->order;
        $client = $order?->client;
        $clientName = (string) ($client?->full_name ?? $client?->phone ?? '');
        $processedAt = $t->paid_at ?? $t->created_at;

        return [
            'id' => $t->id,
            'process_number' => (string) ($t->fort_id ?: $t->transaction_id ?: 'TXN-'.$t->id),
            'client_name' => $this->bilingual($clientName),
            'request_number' => $order?->order_number !== null ? (string) $order->order_number : null,
            'order_id' => $order?->id,
            'process_date' => $processedAt?->format('Y-m-d'),
            'total_price' => (float) $t->amount,
            'payment_method' => $t->payment_method,
            'status' => $t->status,
        ];
    }
}
