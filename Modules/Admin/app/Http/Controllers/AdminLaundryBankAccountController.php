<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\UploadFilesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Branch\Models\Branch;
use Modules\Vendor\Models\Vendor;

class AdminLaundryBankAccountController extends Controller
{
    protected $uploadFilesService;

    public function __construct(UploadFilesService $uploadFilesService)
    {
        $this->uploadFilesService = $uploadFilesService;
    }

    /**
     * Get bank accounts for a laundry
     * GET /laundries/bankAccount
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

        // Calculate states
        $totalAccounts = DB::table('vendor_bank_accounts')
            ->where('vendor_id', $vendorId)
            ->count();

        $awaitingApproval = DB::table('vendor_bank_accounts')
            ->where('vendor_id', $vendorId)
            ->where('status', 'pending')
            ->count();

        $rejectedAccounts = DB::table('vendor_bank_accounts')
            ->where('vendor_id', $vendorId)
            ->where('status', 'rejected')
            ->count();

        $states = [
            'Total_accounts' => $totalAccounts,
            'awaiting_approval' => $awaitingApproval,
            'rejected_accounts' => $rejectedAccounts,
        ];

        // Get bank accounts log
        $bankAccountsLogQuery = DB::table('vendor_bank_accounts')
            ->where('vendor_id', $vendorId)
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 15));

        $bankAccountsLog = collect($bankAccountsLogQuery->items())->map(function ($account) use ($vendor) {
            return [
                'bank_logo' => null, // Add if you have bank logos
                'Laundry_account' => $vendor->getTranslatedName('ar') ?? $vendor->name,
                'Date' => $account->created_at ? date('d M Y', strtotime($account->created_at)) : null,
                'Status' => $this->mapBankAccountStatus($account->status),
            ];
        });

        // Get bank account requests (pending)
        $bankAccountRequests = DB::table('vendor_bank_accounts')
            ->where('vendor_id', $vendorId)
            ->where('status', 'pending')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($account) use ($vendor) {
                return [
                    'Logo' => $vendor->logo ? (str_starts_with($vendor->logo, 'http') ? $vendor->logo : config('app.url').$vendor->logo) : null,
                    'laundry_name' => $vendor->getTranslatedName('ar') ?? $vendor->name,
                    'Iban' => $account->iban_number,
                    'Status' => 'add', // or 'edit' if updating existing
                    'Date' => $account->created_at ? date('d M Y', strtotime($account->created_at)) : null,
                ];
            });

        // Get approved bank accounts
        $bankAccounts = DB::table('vendor_bank_accounts')
            ->where('vendor_id', $vendorId)
            ->where('status', 'approved')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($account) {
                $branch = Branch::where('vendor_id', $account->vendor_id)->first();

                return [
                    'name' => $branch ? ($branch->getTranslation('name', 'ar') ?? $branch->name) : null,
                    'bank_name' => $account->bank_name,
                    'Account_owner' => $account->account_holder,
                    'Iban' => $account->iban_number,
                    'Adding_date' => $account->created_at ? date('d M Y', strtotime($account->created_at)) : null,
                    'approval_status' => $account->status,
                ];
            });

        return successResponse([
            'States' => $states,
            'bank_accounts_log' => $bankAccountsLog,
            'Bank_accounts_requests' => $bankAccountRequests,
            'Bank_accounts' => $bankAccounts,
        ], 'Bank accounts retrieved successfully');
    }

    /**
     * Get single bank account
     * GET /laundries/bankAccount/:id
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $account = DB::table('vendor_bank_accounts')->find($id);

        if (! $account) {
            return notFoundResponse('Bank account not found');
        }

        $branch = Branch::where('vendor_id', $account->vendor_id)->first();

        $accountData = [
            'name' => $branch ? ($branch->getTranslation('name', 'ar') ?? $branch->name) : null,
            'bank_name' => $account->bank_name,
            'Account_owner' => $account->account_holder,
            'Iban' => $account->iban_number,
            'Adding_date' => $account->created_at ? date('d M Y', strtotime($account->created_at)) : null,
            'approval_status' => $account->status,
        ];

        return successResponse($accountData, 'Bank account retrieved successfully');
    }

    /**
     * Accept bank account request
     * POST /laundries/bank_account/is_accepted
     */
    public function acceptBankAccount(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vendor_id' => 'required|exists:vendors,id',
            'bank_account_id' => 'required|exists:vendor_bank_accounts,id',
        ]);

        $bankAccount = DB::table('vendor_bank_accounts')
            ->where('id', $validated['bank_account_id'])
            ->where('vendor_id', $validated['vendor_id'])
            ->first();

        if (! $bankAccount) {
            return notFoundResponse('Bank account not found');
        }

        if ($bankAccount->status !== 'pending') {
            return errorResponse('Bank account is not pending', null, 400);
        }

        // Branch-private accounts must be approved by the vendor first.
        if (! empty($bankAccount->branch_id) && ($bankAccount->vendor_status ?? 'approved') !== 'approved') {
            return errorResponse(__('vendor.bank_account_needs_vendor_approval_first'), null, 400);
        }

        DB::table('vendor_bank_accounts')
            ->where('id', $validated['bank_account_id'])
            ->update([
                'status' => 'approved',
            ]);

        return successResponse(null, 'Bank account accepted successfully');
    }

    /**
     * Reject bank account request
     * POST /laundries/bank_account/is_rejected
     */
    public function rejectBankAccount(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vendor_id' => 'required|exists:vendors,id',
            'bank_account_id' => 'required|exists:vendor_bank_accounts,id',
            'rejection_reason' => 'nullable|string',
        ]);

        $bankAccount = DB::table('vendor_bank_accounts')
            ->where('id', $validated['bank_account_id'])
            ->where('vendor_id', $validated['vendor_id'])
            ->first();

        if (! $bankAccount) {
            return notFoundResponse('Bank account not found');
        }

        if ($bankAccount->status !== 'pending') {
            return errorResponse('Bank account is not pending', null, 400);
        }

        DB::table('vendor_bank_accounts')
            ->where('id', $validated['bank_account_id'])
            ->update([
                'status' => 'rejected',
                'rejection_reason' => $validated['rejection_reason'] ?? null,
            ]);

        return successResponse(null, 'Bank account rejected successfully');
    }

    /**
     * Delete bank account
     * DELETE /laundries/bank_accounts/:id
     */
    public function destroy(int $id): JsonResponse
    {
        $account = DB::table('vendor_bank_accounts')->find($id);

        if (! $account) {
            return notFoundResponse('Bank account not found');
        }

        DB::table('vendor_bank_accounts')->where('id', $id)->delete();

        return successResponse(null, 'Bank account deleted successfully');
    }

    /**
     * Map bank account status
     */
    private function mapBankAccountStatus($status): string
    {
        $statusMap = [
            'pending' => 'pending',
            'approved' => 'completed',
            'rejected' => 'rejected',
        ];

        return $statusMap[$status] ?? $status;
    }
}
