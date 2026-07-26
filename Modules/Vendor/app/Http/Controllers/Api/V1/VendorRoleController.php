<?php

namespace Modules\Vendor\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\Vendor\Models\VendorPermission;
use Modules\Vendor\Models\VendorRole;

class VendorRoleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $employee = $request->user();

        if (! $employee->hasVendorPermission('view_roles')) {
            return errorResponse(__('vendor.unauthorized_action'), null, 403);
        }

        $locale = $request->input('language', app()->getLocale());
        $perPage = (int) $request->input('per_page', 15);

        $query = VendorRole::with('permissions')
            ->where('vendor_id', $employee->vendor_id);

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('display_name_ar', 'like', "%{$search}%")
                    ->orWhere('display_name_en', 'like', "%{$search}%");
            });
        }

        $roles = $query->orderBy('name')->paginate($perPage);
        $roles->through(fn (VendorRole $role) => $this->formatRoleSummary($role, $locale));

        return successResponse($roles, __('vendor.roles_retrieved'));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $employee = $request->user();

        if (! $employee->hasVendorPermission('view_roles')) {
            return errorResponse(__('vendor.unauthorized_action'), null, 403);
        }

        $locale = $request->input('language', app()->getLocale());
        $role = VendorRole::with('permissions')
            ->where('vendor_id', $employee->vendor_id)
            ->find($id);

        if (! $role) {
            return notFoundResponse(__('vendor.role_not_found'));
        }

        return successResponse(
            $this->formatRoleDetail($role, $locale),
            __('vendor.roles_retrieved')
        );
    }

    public function store(Request $request): JsonResponse
    {
        $employee = $request->user();

        if (! $employee->hasVendorPermission('manage_roles')) {
            return errorResponse(__('vendor.unauthorized_action'), null, 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9_]+$/'],
            'display_name_ar' => ['required', 'string', 'max:255'],
            'display_name_en' => ['required', 'string', 'max:255'],
            'description_ar' => ['nullable', 'string'],
            'description_en' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'permission_ids' => ['nullable'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $permissionIds = $this->resolvePermissionIds($request);
        if ($permissionIds instanceof JsonResponse) {
            return $permissionIds;
        }

        $nameExists = VendorRole::where('vendor_id', $employee->vendor_id)
            ->where('name', $request->input('name'))
            ->exists();

        if ($nameExists) {
            return validationErrorResponse(['name' => [__('vendor.role_name_exists')]]);
        }

        try {
            DB::beginTransaction();

            $role = VendorRole::create([
                'vendor_id' => $employee->vendor_id,
                'name' => $request->input('name'),
                'display_name_ar' => $request->input('display_name_ar'),
                'display_name_en' => $request->input('display_name_en'),
                'description_ar' => $request->input('description_ar'),
                'description_en' => $request->input('description_en'),
                'is_active' => $request->boolean('is_active', true),
                'is_system' => false,
            ]);

            if ($permissionIds !== null) {
                $role->permissions()->sync($permissionIds);
            }

            DB::commit();

            $role->load('permissions');
            $locale = $request->input('language', app()->getLocale());

            return successResponse(
                $this->formatRoleDetail($role, $locale),
                __('vendor.role_created'),
                201
            );
        } catch (\Exception $e) {
            DB::rollBack();

            return errorResponse(__('messages.something_went_wrong'), null, 500);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $employee = $request->user();

        if (! $employee->hasVendorPermission('manage_roles')) {
            return errorResponse(__('vendor.unauthorized_action'), null, 403);
        }

        $role = VendorRole::where('vendor_id', $employee->vendor_id)->find($id);

        if (! $role) {
            return notFoundResponse(__('vendor.role_not_found'));
        }

        if ($role->is_system) {
            return errorResponse(__('vendor.cannot_modify_system_role'), null, 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'string', 'max:255', 'regex:/^[a-z0-9_]+$/'],
            'display_name_ar' => ['sometimes', 'string', 'max:255'],
            'display_name_en' => ['sometimes', 'string', 'max:255'],
            'description_ar' => ['nullable', 'string'],
            'description_en' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'permission_ids' => ['nullable'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $permissionIds = $this->resolvePermissionIds($request);
        if ($permissionIds instanceof JsonResponse) {
            return $permissionIds;
        }

        if ($request->filled('name')) {
            $nameExists = VendorRole::where('vendor_id', $employee->vendor_id)
                ->where('name', $request->input('name'))
                ->where('id', '!=', $role->id)
                ->exists();

            if ($nameExists) {
                return validationErrorResponse(['name' => [__('vendor.role_name_exists')]]);
            }
        }

        try {
            DB::beginTransaction();

            $role->update($request->only([
                'name',
                'display_name_ar',
                'display_name_en',
                'description_ar',
                'description_en',
                'is_active',
            ]));

            if ($permissionIds !== null) {
                $role->permissions()->sync($permissionIds);
            }

            DB::commit();

            $role->refresh()->load('permissions');
            $locale = $request->input('language', app()->getLocale());

            return successResponse(
                $this->formatRoleDetail($role, $locale),
                __('vendor.role_updated')
            );
        } catch (\Exception $e) {
            DB::rollBack();

            return errorResponse(__('messages.something_went_wrong'), null, 500);
        }
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $employee = $request->user();

        if (! $employee->hasVendorPermission('manage_roles')) {
            return errorResponse(__('vendor.unauthorized_action'), null, 403);
        }

        $role = VendorRole::where('vendor_id', $employee->vendor_id)->find($id);

        if (! $role) {
            return notFoundResponse(__('vendor.role_not_found'));
        }

        if ($role->is_system) {
            return errorResponse(__('vendor.cannot_modify_system_role'), null, 403);
        }

        if ($role->employees()->exists() || $role->branchAssignments()->exists()) {
            return errorResponse(__('vendor.role_in_use'), null, 400);
        }

        $role->permissions()->detach();
        $role->delete();

        return successResponse(null, __('vendor.role_deleted'));
    }

    public function permissions(Request $request): JsonResponse
    {
        $employee = $request->user();

        if (! $employee->hasVendorPermission('view_roles')) {
            return errorResponse(__('vendor.unauthorized_action'), null, 403);
        }

        $locale = $request->input('language', app()->getLocale());

        return successResponse(
            VendorPermission::getAllGrouped($locale),
            __('vendor.permissions_retrieved')
        );
    }

    private function resolvePermissionIds(Request $request): array|JsonResponse|null
    {
        if (! $request->has('permission_ids')) {
            return null;
        }

        $permissionIds = $request->input('permission_ids');

        if (is_string($permissionIds) && strtolower(trim($permissionIds)) === 'all') {
            return VendorPermission::query()->pluck('id')->all();
        }

        if (! is_array($permissionIds)) {
            return validationErrorResponse([
                'permission_ids' => [__('vendor.permission_ids_invalid')],
            ]);
        }

        $validator = Validator::make(
            ['permission_ids' => $permissionIds],
            [
                'permission_ids' => ['array', 'min:1'],
                'permission_ids.*' => ['integer', 'exists:vendor_permissions,id'],
            ]
        );

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        return array_values(array_unique(array_map('intval', $permissionIds)));
    }

    private function formatRoleSummary(VendorRole $role, string $locale): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'display_name' => $role->getDisplayName($locale),
            'description' => $role->getDescription($locale),
            'is_active' => $role->is_active,
            'is_system' => $role->is_system,
            'permissions_count' => $role->permissions->count(),
            'employees_count' => $role->employees()->count(),
            'created_at' => $role->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    private function formatRoleDetail(VendorRole $role, string $locale): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'display_name_ar' => $role->display_name_ar,
            'display_name_en' => $role->display_name_en,
            'display_name' => $role->getDisplayName($locale),
            'description_ar' => $role->description_ar,
            'description_en' => $role->description_en,
            'description' => $role->getDescription($locale),
            'is_active' => $role->is_active,
            'is_system' => $role->is_system,
            'permissions' => $role->permissions->map(function ($permission) use ($locale) {
                return [
                    'id' => $permission->id,
                    'name' => $permission->name,
                    'display_name' => $permission->getDisplayName($locale),
                    'group' => $permission->group,
                ];
            })->values(),
            'permission_ids' => $role->permissions->pluck('id')->values(),
            'employees_count' => $role->employees()->count(),
            'created_at' => $role->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $role->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
