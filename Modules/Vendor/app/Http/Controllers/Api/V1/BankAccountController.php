<?php

namespace Modules\Vendor\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\UploadFilesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Admin\Models\Bank;
use Modules\Branch\Models\Branch;
use Modules\Vendor\Models\VendorBankAccount;
use Modules\Vendor\Support\VendorBranchFilter;

class BankAccountController extends Controller
{
    protected UploadFilesService $uploadFilesService;

    public function __construct(UploadFilesService $uploadFilesService)
    {
        $this->uploadFilesService = $uploadFilesService;
    }

    /**
     * List banks the vendor/branch can choose from (admin-managed, active only).
     * GET /api/v1/vendor/bank-accounts/banks
     */
    public function banks(Request $request): JsonResponse
    {
        $lang = app()->getLocale();

        $banks = Bank::where('is_active', true)
            ->orderBy('id')
            ->get()
            ->map(fn (Bank $bank) => [
                'id' => $bank->id,
                'name' => $bank->getTranslation('name', $lang),
                'logo' => $this->uploadFilesService->getFullUrl($bank->logo),
            ]);

        return successResponse($banks, __('bank.banks_retrieved'));
    }

    /**
     * List the vendor's bank accounts (general + branch).
     * GET /api/v1/vendor/bank-accounts
     */
    public function index(Request $request): JsonResponse
    {
        $employee = $request->user();

        $query = VendorBankAccount::with(['bank', 'branch'])
            ->where('vendor_id', $employee->vendor_id);

        if (VendorBranchFilter::hasFilter($request)) {
            $branchIds = VendorBranchFilter::resolveIds($request, (int) $employee->vendor_id);

            if ($branchIds->isEmpty()) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where(function ($q) use ($branchIds) {
                    $q->whereNull('branch_id')
                        ->orWhereIn('branch_id', $branchIds);
                });
            }
        }

        $accounts = $query->orderBy('created_at', 'desc')
            ->get()
            ->map(fn (VendorBankAccount $account) => $this->present($account));

        return successResponse($accounts, __('vendor.bank_accounts_retrieved'));
    }

    /**
     * List branch bank accounts awaiting the vendor's approval.
     * GET /api/v1/vendor/bank-accounts/branch-requests
     */
    public function getBranchRequests(Request $request): JsonResponse
    {
        $employee = $request->user();

        $query = VendorBankAccount::with(['bank', 'branch'])
            ->where('vendor_id', $employee->vendor_id)
            ->whereNotNull('branch_id')
            ->where('vendor_status', 'pending');

        if (VendorBranchFilter::hasFilter($request)) {
            $branchIds = VendorBranchFilter::resolveIds($request, (int) $employee->vendor_id);

            if ($branchIds->isEmpty()) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('branch_id', $branchIds);
            }
        }

        $accounts = $query->orderBy('created_at', 'desc')
            ->get()
            ->map(fn (VendorBankAccount $account) => $this->present($account));

        return successResponse($accounts, __('vendor.branch_bank_account_requests_retrieved'));
    }

    /**
     * Add a bank account.
     *  - Without branch_id: a general (vendor-wide) account for all branches.
     *    Auto-approved at the vendor level; needs admin approval.
     *  - With branch_id: a branch-private account. Needs the vendor to approve
     *    it first, then the admin.
     * POST /api/v1/vendor/bank-accounts
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'bank_id' => ['required', 'integer', 'exists:banks,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'iban_number' => ['required', 'string', 'max:34'],
            'account_holder' => ['required', 'string', 'max:255'],
            'iban_document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $employee = $request->user();

        if (! $employee->vendor_id) {
            return errorResponse(__('vendor.employee_not_associated_with_vendor'), null, 400);
        }

        $bank = Bank::where('id', $request->bank_id)->where('is_active', true)->first();
        if (! $bank) {
            return errorResponse(__('bank.bank_not_found'), null, 404);
        }

        $isBranchAccount = $request->filled('branch_id');

        if ($isBranchAccount) {
            // The branch must belong to this vendor.
            $branch = Branch::where('id', $request->branch_id)
                ->where('vendor_id', $employee->vendor_id)
                ->first();

            if (! $branch) {
                return errorResponse(__('vendor.branch_not_found_or_not_yours'), null, 404);
            }

            // A non-management (branch) employee may only add an account for
            // their own branch.
            if (! $employee->hasManagementPermissions()
                && $employee->branch_id
                && (int) $employee->branch_id !== (int) $request->branch_id) {
                return forbiddenResponse(__('vendor.branch_not_found_or_not_yours'));
            }
        } elseif (! $employee->hasManagementPermissions()) {
            // Only owner/manager can add a general vendor-wide account.
            return forbiddenResponse(__('vendor.no_permission_to_add_general_account'));
        }

        $documentPath = $this->uploadFilesService->uploadFile(
            $request->file('iban_document'),
            'vendors/bank-documents'
        );

        $account = VendorBankAccount::create([
            'vendor_id' => $employee->vendor_id,
            'bank_id' => $bank->id,
            'branch_id' => $isBranchAccount ? (int) $request->branch_id : null,
            'bank_name' => $bank->getTranslation('name', 'en'),
            'iban_number' => $request->iban_number,
            'account_holder' => $request->account_holder,
            'iban_document' => $documentPath,
            // Admin approval level (always required).
            'status' => 'pending',
            'rejection_reason' => null,
            // Vendor approval level: branch accounts start pending, general are auto-approved.
            'vendor_status' => $isBranchAccount ? 'pending' : 'approved',
            'vendor_rejection_reason' => null,
        ]);

        $account->load(['bank', 'branch']);

        return successResponse($this->present($account), __('vendor.bank_account_added'), 201);
    }

    /**
     * Update a bank account (general or branch). Because bank details changed,
     * the account is sent back for re-approval:
     *  - General account  -> admin approval (status = pending).
     *  - Branch account    -> vendor approval then admin approval
     *    (vendor_status = pending, status = pending).
     * PUT /api/v1/vendor/bank-accounts/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $employee = $request->user();

        $account = VendorBankAccount::where('id', $id)
            ->where('vendor_id', $employee->vendor_id)
            ->first();

        if (! $account) {
            return notFoundResponse(__('vendor.bank_account_not_found'));
        }

        $isBranchAccount = $account->branch_id !== null;

        // Permission: branch employees may only edit their own branch account;
        // general accounts require management permissions.
        if ($isBranchAccount) {
            if (! $employee->hasManagementPermissions()
                && $employee->branch_id
                && (int) $employee->branch_id !== (int) $account->branch_id) {
                return forbiddenResponse(__('vendor.branch_not_found_or_not_yours'));
            }
        } elseif (! $employee->hasManagementPermissions()) {
            return forbiddenResponse(__('vendor.no_permission_to_add_general_account'));
        }

        $validator = Validator::make($request->all(), [
            'bank_id' => ['sometimes', 'integer', 'exists:banks,id'],
            'iban_number' => ['sometimes', 'string', 'max:34'],
            'account_holder' => ['sometimes', 'string', 'max:255'],
            'iban_document' => ['sometimes', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $data = [];

        if ($request->filled('bank_id')) {
            $bank = Bank::where('id', $request->bank_id)->where('is_active', true)->first();
            if (! $bank) {
                return errorResponse(__('bank.bank_not_found'), null, 404);
            }
            $data['bank_id'] = $bank->id;
            $data['bank_name'] = $bank->getTranslation('name', 'en');
        }

        if ($request->has('iban_number')) {
            $data['iban_number'] = $request->iban_number;
        }

        if ($request->has('account_holder')) {
            $data['account_holder'] = $request->account_holder;
        }

        if ($request->hasFile('iban_document')) {
            $data['iban_document'] = $this->uploadFilesService->uploadFile(
                $request->file('iban_document'),
                'vendors/bank-documents',
                $account->iban_document
            );
        }

        if (empty($data)) {
            return errorResponse(__('vendor.no_data_to_update'), null, 400);
        }

        // Changed bank details must be re-approved.
        $data['status'] = 'pending';
        $data['rejection_reason'] = null;
        if ($isBranchAccount) {
            $data['vendor_status'] = 'pending';
            $data['vendor_rejection_reason'] = null;
        }

        $account->update($data);
        $account->load(['bank', 'branch']);

        return successResponse($this->present($account), __('vendor.bank_account_updated'));
    }

    /**
     * Vendor approves a branch's bank account (vendor approval level).
     * POST /api/v1/vendor/bank-accounts/branch-requests/{id}/approve
     */
    public function approveBranchRequest(Request $request, int $id): JsonResponse
    {
        $employee = $request->user();

        if (! $employee->hasManagementPermissions()) {
            return forbiddenResponse(__('vendor.no_permission_to_approve_branch_account'));
        }

        $account = VendorBankAccount::where('id', $id)
            ->where('vendor_id', $employee->vendor_id)
            ->whereNotNull('branch_id')
            ->first();

        if (! $account) {
            return notFoundResponse(__('vendor.bank_account_not_found'));
        }

        if ($account->vendor_status !== 'pending') {
            return errorResponse(__('vendor.bank_account_not_pending'), null, 400);
        }

        $account->update([
            'vendor_status' => 'approved',
            'vendor_rejection_reason' => null,
        ]);

        $account->load(['bank', 'branch']);

        return successResponse($this->present($account), __('vendor.branch_bank_account_approved'));
    }

    /**
     * Vendor rejects a branch's bank account (vendor approval level).
     * POST /api/v1/vendor/bank-accounts/branch-requests/{id}/reject
     */
    public function rejectBranchRequest(Request $request, int $id): JsonResponse
    {
        $employee = $request->user();

        if (! $employee->hasManagementPermissions()) {
            return forbiddenResponse(__('vendor.no_permission_to_approve_branch_account'));
        }

        $validator = Validator::make($request->all(), [
            'rejection_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $account = VendorBankAccount::where('id', $id)
            ->where('vendor_id', $employee->vendor_id)
            ->whereNotNull('branch_id')
            ->first();

        if (! $account) {
            return notFoundResponse(__('vendor.bank_account_not_found'));
        }

        if ($account->vendor_status !== 'pending') {
            return errorResponse(__('vendor.bank_account_not_pending'), null, 400);
        }

        $account->update([
            'vendor_status' => 'rejected',
            'vendor_rejection_reason' => $request->input('rejection_reason'),
        ]);

        $account->load(['bank', 'branch']);

        return successResponse($this->present($account), __('vendor.branch_bank_account_rejected'));
    }

    private function present(VendorBankAccount $account): array
    {
        $lang = app()->getLocale();

        return [
            'account_id' => $account->id,
            'bank_id' => $account->bank_id,
            'bank_name' => $account->bank
                ? $account->bank->getTranslation('name', $lang)
                : $account->bank_name,
            'bank_logo' => $account->bank ? $this->uploadFilesService->getFullUrl($account->bank->logo) : null,
            'branch_id' => $account->branch_id,
            'branch_name' => $account->branch ? $account->branch->getTranslation('name', $lang) : null,
            'scope' => $account->branch_id ? 'branch' : 'vendor',
            'iban_number' => $account->iban_number,
            'account_holder' => $account->account_holder,
            'iban_document_url' => $this->uploadFilesService->getFullUrl($account->iban_document),
            'vendor_status' => $account->vendor_status,
            'vendor_rejection_reason' => $account->vendor_rejection_reason,
            'status' => $account->status ?? 'pending',
            'rejection_reason' => $account->rejection_reason,
            'is_fully_approved' => $account->isFullyApproved(),
        ];
    }
}
