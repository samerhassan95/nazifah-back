<?php

namespace Modules\Vendor\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\UploadFilesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Modules\Branch\Models\Branch;
use Modules\Vendor\Models\VendorEmployee;
use Modules\Vendor\Models\VendorRole;
use Modules\Vendor\Services\VendorDefaultRolesService;

class VendorEmployeeController extends Controller
{
    protected $uploadFilesService;

    public function __construct(UploadFilesService $uploadFilesService)
    {
        $this->uploadFilesService = $uploadFilesService;
    }

    public function index(Request $request): JsonResponse
    {
        $employee = $request->user();

        if (! $employee->hasVendorPermission('view_employees')) {
            return errorResponse(__('vendor.unauthorized_action'), null, 403);
        }

        $query = VendorEmployee::where('vendor_id', $employee->vendor_id)
            ->with(['branch', 'vendorRole', 'branchAssignments.branch', 'branchAssignments.role']);

        if ($request->has('branch_id')) {
            $query->byBranch((int) $request->branch_id);
        }

        if ($request->has('role')) {
            $query->where('role', $request->role);
        }

        if ($request->has('vendor_role_id')) {
            $query->where('vendor_role_id', $request->vendor_role_id);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $employees = $query->orderBy('created_at', 'desc')->paginate(15);
        $employees->getCollection()->each->reconcilePrimaryRoleFromAssignments();
        $employees->through(fn ($emp) => $this->formatEmployee($emp));

        return successResponse($employees, __('vendor.employees_retrieved'));
    }

    public function store(Request $request): JsonResponse
    {
        $authEmployee = $request->user();

        if (! $authEmployee->hasVendorPermission('manage_employees')) {
            return errorResponse(__('vendor.unauthorized_action'), null, 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:vendor_employees,email'],
            'phone' => ['required', 'string', 'regex:/^\+?[0-9]{10,15}$/', 'unique:vendor_employees,phone'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'vendor_role_id' => ['nullable', 'integer', 'exists:vendor_roles,id'],
            'role' => ['nullable', 'in:manager,employee'],
            'branch_assignments' => ['nullable', 'array', 'min:1'],
            'branch_assignments.*.branch_id' => ['required', 'integer', 'exists:branches,id'],
            'branch_assignments.*.vendor_role_id' => ['nullable', 'integer', 'exists:vendor_roles,id'],
            'branch_ids' => ['nullable', 'array', 'min:1'],
            'branch_ids.*' => ['integer', 'exists:branches,id'],
            'image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:5120'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $phone = normalizePhone($request->phone);
        if (VendorEmployee::findByPhone($phone)) {
            return validationErrorResponse(['phone' => [__('validation.unique', ['attribute' => 'phone'])]]);
        }

        $branchAssignments = $this->normalizeBranchAssignments($request, $authEmployee->vendor_id);
        if ($branchAssignments instanceof JsonResponse) {
            return $branchAssignments;
        }

        $vendorRoleId = $this->resolveVendorRoleId($request, $authEmployee->vendor_id, $branchAssignments);
        if (! $vendorRoleId) {
            return validationErrorResponse(['vendor_role_id' => [__('vendor.role_required_for_branch')]]);
        }

        try {
            DB::beginTransaction();

            $data = [
                'vendor_id' => $authEmployee->vendor_id,
                'branch_id' => $branchAssignments[0]['branch_id'] ?? $request->branch_id,
                'vendor_role_id' => $vendorRoleId,
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $phone,
                'role' => $request->filled('role')
                    ? $request->input('role')
                    : VendorEmployee::legacyRoleEnumForVendorRoleId($vendorRoleId),
                'password' => Hash::make('temp_password_'.time()),
                'is_active' => true,
                'is_verified' => false,
            ];

            if ($request->hasFile('image')) {
                $data['image'] = $this->uploadFilesService->uploadLogo(
                    $request->file('image'),
                    'vendor_employees/images'
                );
            }

            $employee = VendorEmployee::create($data);

            if (! empty($branchAssignments)) {
                $employee->syncBranchAssignments($branchAssignments);
            }

            DB::commit();

            $employee->load(['branch', 'vendorRole', 'branchAssignments.branch', 'branchAssignments.role']);

            return successResponse(
                $this->formatEmployee($employee, true),
                __('vendor.employee_created'),
                201
            );
        } catch (\Exception $e) {
            DB::rollBack();

            return errorResponse(__('messages.something_went_wrong'), null, 500);
        }
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $authEmployee = $request->user();

        if (! $authEmployee->hasVendorPermission('view_employees')) {
            return errorResponse(__('vendor.unauthorized_action'), null, 403);
        }

        $employee = VendorEmployee::where('id', $id)
            ->where('vendor_id', $authEmployee->vendor_id)
            ->with(['branch', 'vendorRole', 'branchAssignments.branch', 'branchAssignments.role'])
            ->first();

        if (! $employee) {
            return notFoundResponse(__('vendor.employee_not_found'));
        }

        return successResponse(
            $this->formatEmployee($employee, true),
            __('vendor.employees_retrieved')
        );
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $authEmployee = $request->user();

        if (! $authEmployee->hasVendorPermission('manage_employees')) {
            return errorResponse(__('vendor.unauthorized_action'), null, 403);
        }

        $employee = VendorEmployee::where('id', $id)
            ->where('vendor_id', $authEmployee->vendor_id)
            ->first();

        if (! $employee) {
            return notFoundResponse(__('vendor.employee_not_found'));
        }

        if ($employee->isOwner()) {
            return errorResponse(__('vendor.cannot_modify_owner'), null, 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'unique:vendor_employees,email,'.$employee->id],
            'phone' => ['sometimes', 'string', 'regex:/^\+?[0-9]{10,15}$/', 'unique:vendor_employees,phone,'.$employee->id],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'vendor_role_id' => ['nullable', 'integer', 'exists:vendor_roles,id'],
            'role' => ['sometimes', 'in:manager,employee'],
            'branch_assignments' => ['nullable', 'array', 'min:1'],
            'branch_assignments.*.branch_id' => ['required', 'integer', 'exists:branches,id'],
            'branch_assignments.*.vendor_role_id' => ['nullable', 'integer', 'exists:vendor_roles,id'],
            'branch_ids' => ['nullable', 'array', 'min:1'],
            'branch_ids.*' => ['integer', 'exists:branches,id'],
            'is_active' => ['sometimes', 'boolean'],
            'image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:5120'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $branchAssignments = null;
        if ($request->has('branch_assignments')) {
            $branchAssignments = $this->normalizeBranchAssignments($request, $authEmployee->vendor_id);
            if ($branchAssignments instanceof JsonResponse) {
                return $branchAssignments;
            }
        } elseif ($request->has('branch_id') && $request->branch_id) {
            if (! Branch::where('id', $request->branch_id)->where('vendor_id', $authEmployee->vendor_id)->exists()) {
                return errorResponse(__('validation.exists', ['attribute' => 'branch_id']), null, 400);
            }
        }

        if ($request->filled('vendor_role_id')) {
            $roleValid = VendorRole::where('id', $request->vendor_role_id)
                ->where('vendor_id', $authEmployee->vendor_id)
                ->exists();

            if (! $roleValid) {
                return errorResponse(__('vendor.role_not_found'), null, 400);
            }
        }

        try {
            DB::beginTransaction();

            $data = [];

            foreach (['name', 'email', 'branch_id', 'vendor_role_id', 'role'] as $field) {
                if ($request->has($field)) {
                    $data[$field] = $request->input($field);
                }
            }

            if ($request->has('phone')) {
                $normalizedPhone = normalizePhone($request->phone);
                $phoneExists = VendorEmployee::query()
                    ->byPhone($normalizedPhone)
                    ->where('id', '!=', $employee->id)
                    ->exists();

                if ($phoneExists) {
                    return validationErrorResponse(['phone' => [__('validation.unique', ['attribute' => 'phone'])]]);
                }

                $data['phone'] = $normalizedPhone;
            }

            if ($request->has('is_active')) {
                $data['is_active'] = $request->boolean('is_active');
            }

            if ($request->hasFile('image')) {
                $data['image'] = $this->uploadFilesService->uploadLogo(
                    $request->file('image'),
                    'vendor_employees/images',
                    $employee->image
                );
            }

            if (! empty($data)) {
                $employee->update($data);
            }

            if (is_array($branchAssignments)) {
                $employee->syncBranchAssignments($branchAssignments);
            } elseif ($request->filled('vendor_role_id')) {
                $employee->update([
                    'vendor_role_id' => (int) $request->vendor_role_id,
                    'role' => $request->filled('role')
                        ? $request->input('role')
                        : VendorEmployee::legacyRoleEnumForVendorRoleId((int) $request->vendor_role_id),
                ]);
            }

            DB::commit();

            $employee->refresh()->load(['branch', 'vendorRole', 'branchAssignments.branch', 'branchAssignments.role']);

            return successResponse(
                $this->formatEmployee($employee, true),
                __('vendor.employee_updated')
            );
        } catch (\Exception $e) {
            DB::rollBack();

            return errorResponse(__('messages.something_went_wrong'), null, 500);
        }
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $authEmployee = $request->user();

        if (! $authEmployee->hasVendorPermission('manage_employees')) {
            return errorResponse(__('vendor.unauthorized_action'), null, 403);
        }

        $employee = VendorEmployee::where('id', $id)
            ->where('vendor_id', $authEmployee->vendor_id)
            ->first();

        if (! $employee) {
            return notFoundResponse(__('vendor.employee_not_found'));
        }

        if ($employee->isOwner()) {
            return errorResponse(__('vendor.cannot_modify_owner'), null, 403);
        }

        if ($employee->id === $authEmployee->id) {
            return errorResponse(__('vendor.cannot_modify_owner'), null, 403);
        }

        try {
            if ($employee->image) {
                $this->uploadFilesService->deleteFile($employee->image);
            }

            $employee->branchAssignments()->delete();
            $employee->delete();

            return successResponse(null, __('vendor.employee_deleted'));
        } catch (\Exception $e) {
            return errorResponse(__('messages.something_went_wrong'), null, 500);
        }
    }

    public function toggleBan(Request $request, int $id): JsonResponse
    {
        $authEmployee = $request->user();

        if (! $authEmployee->hasVendorPermission('manage_employees')) {
            return errorResponse(__('vendor.unauthorized_action'), null, 403);
        }

        $employee = VendorEmployee::where('id', $id)
            ->where('vendor_id', $authEmployee->vendor_id)
            ->first();

        if (! $employee) {
            return notFoundResponse(__('vendor.employee_not_found'));
        }

        if ($employee->isOwner()) {
            return errorResponse(__('vendor.cannot_modify_owner'), null, 403);
        }

        if ($employee->id === $authEmployee->id) {
            return errorResponse(__('vendor.cannot_modify_owner'), null, 403);
        }

        $validator = Validator::make($request->all(), [
            'ban_reason' => ['required_if:is_banned,true', 'nullable', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            if ($employee->is_banned) {
                $employee->update([
                    'is_banned' => false,
                    'ban_reason' => null,
                    'banned_at' => null,
                ]);
                $message = __('vendor.employee_unbanned');
            } else {
                $employee->update([
                    'is_banned' => true,
                    'ban_reason' => $request->ban_reason,
                    'banned_at' => now(),
                ]);
                $message = __('vendor.employee_banned');
            }

            return successResponse([
                'id' => $employee->id,
                'name' => $employee->name,
                'is_banned' => $employee->is_banned,
                'ban_reason' => $employee->ban_reason,
                'banned_at' => $employee->banned_at?->format('Y-m-d H:i:s'),
            ], $message);
        } catch (\Exception $e) {
            return errorResponse(__('messages.something_went_wrong'), null, 500);
        }
    }

    private function formatEmployee(VendorEmployee $employee, bool $detailed = false): array
    {
        $locale = app()->getLocale();
        $employee->reconcilePrimaryRoleFromAssignments();
        $employee->loadMissing(['branch', 'vendorRole', 'branchAssignments.branch']);

        $role = $employee->vendorRole;

        $data = [
            'id' => $employee->id,
            'vendor_id' => $employee->vendor_id,
            'branch_id' => $employee->branch_id,
            'vendor_role_id' => $role?->id,
            'name' => $employee->name,
            'email' => $employee->email,
            'phone' => $employee->phone,
            'image' => $this->uploadFilesService->getFullUrl($employee->image),
            'role' => $employee->getApiRoleName(),
            'is_active' => $employee->is_active,
            'is_verified' => $employee->is_verified,
            'is_banned' => $employee->is_banned,
            'branch' => $employee->branch ? [
                'id' => $employee->branch->id,
                'name' => $employee->branch->name,
            ] : null,
            'vendor_role' => $role ? [
                'id' => $role->id,
                'name' => $role->name,
                'display_name' => $role->getDisplayName($locale),
            ] : null,
            'branches' => $employee->branchAssignments->map(fn ($assignment) => [
                'id' => $assignment->branch_id,
                'name' => $assignment->branch?->name,
            ])->values(),
            'branch_assignments' => $employee->branchAssignments->map(fn ($assignment) => [
                'branch_id' => $assignment->branch_id,
                'branch_name' => $assignment->branch?->name,
            ])->values(),
            'created_at' => $employee->created_at?->format('Y-m-d H:i:s'),
        ];

        if ($detailed) {
            $data['ban_reason'] = $employee->ban_reason;
            $data['access'] = $employee->getAccessPayload($locale);
            $data['updated_at'] = $employee->updated_at?->format('Y-m-d H:i:s');
        }

        return $data;
    }

    private function normalizeBranchAssignments(Request $request, int $vendorId): array|JsonResponse
    {
        $branchIds = [];
        $requestedRoleIds = collect();

        if ($request->filled('branch_ids')) {
            $branchIds = collect($request->input('branch_ids'))
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
        } elseif ($request->filled('branch_assignments')) {
            $assignments = collect($request->input('branch_assignments'))
                ->unique('branch_id')
                ->values();

            $branchIds = $assignments->pluck('branch_id')->map(fn ($id) => (int) $id)->all();
            $requestedRoleIds = $assignments->pluck('vendor_role_id')->filter()->unique()->values();
        } elseif ($request->filled('branch_id')) {
            $branchIds = [(int) $request->branch_id];
        }

        if ($branchIds === []) {
            return [];
        }

        $vendorRoleId = $this->resolveSingleVendorRoleId($request, $vendorId, $requestedRoleIds->all());
        if ($vendorRoleId instanceof JsonResponse) {
            return $vendorRoleId;
        }
        if (! $vendorRoleId) {
            return validationErrorResponse(['vendor_role_id' => [__('vendor.role_required_for_branch')]]);
        }

        foreach ($branchIds as $branchId) {
            $branchValid = Branch::where('id', $branchId)
                ->where('vendor_id', $vendorId)
                ->exists();

            if (! $branchValid) {
                return errorResponse(__('vendor.branch_not_found_or_not_yours'), null, 400);
            }
        }

        $roleValid = VendorRole::where('id', $vendorRoleId)
            ->where('vendor_id', $vendorId)
            ->exists();

        if (! $roleValid) {
            return errorResponse(__('vendor.role_not_found'), null, 400);
        }

        return array_map(fn (int $branchId) => [
            'branch_id' => $branchId,
            'vendor_role_id' => $vendorRoleId,
        ], $branchIds);
    }

    private function resolveSingleVendorRoleId(Request $request, int $vendorId, array $assignmentRoleIds = []): int|JsonResponse|null
    {
        $uniqueRoleIds = collect($assignmentRoleIds)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($uniqueRoleIds->count() > 1) {
            return validationErrorResponse(['vendor_role_id' => [__('vendor.employee_single_role_only')]]);
        }

        if ($request->filled('vendor_role_id')) {
            $roleId = (int) $request->vendor_role_id;
            if ($uniqueRoleIds->isNotEmpty() && (int) $uniqueRoleIds->first() !== $roleId) {
                return validationErrorResponse(['vendor_role_id' => [__('vendor.employee_single_role_only')]]);
            }

            $role = VendorRole::where('id', $roleId)->where('vendor_id', $vendorId)->first();

            return $role?->id;
        }

        if ($uniqueRoleIds->count() === 1) {
            $roleId = (int) $uniqueRoleIds->first();
            $role = VendorRole::where('id', $roleId)->where('vendor_id', $vendorId)->first();

            return $role?->id;
        }

        if ($request->filled('role')) {
            $roleName = $request->input('role') === 'manager' ? 'branch_manager' : 'customer_support';

            return app(VendorDefaultRolesService::class)
                ->findSystemRole($vendorId, $roleName)?->id;
        }

        return null;
    }

    private function resolveVendorRoleId(Request $request, int $vendorId, array $branchAssignments = []): ?int
    {
        if (! empty($branchAssignments)) {
            return (int) $branchAssignments[0]['vendor_role_id'];
        }

        $resolved = $this->resolveSingleVendorRoleId($request, $vendorId);
        if ($resolved instanceof JsonResponse) {
            return null;
        }

        return $resolved;
    }
}
