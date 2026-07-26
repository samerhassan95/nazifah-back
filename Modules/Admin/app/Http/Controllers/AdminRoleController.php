<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ErrorResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\Admin\Models\Permission;
use Modules\Admin\Models\Role;

class AdminRoleController extends Controller
{
    /**
     * Display a listing of roles
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $locale = $request->input('language', app()->getLocale());
            $perPage = $request->input('per_page', 15);

            $query = Role::with('permissions');

            // Filter by status
            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            // Search
            if ($request->filled('search')) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('display_name_ar', 'like', "%{$search}%")
                        ->orWhere('display_name_en', 'like', "%{$search}%");
                });
            }

            $roles = $query->paginate($perPage);

            $data = $roles->map(function ($role) use ($locale) {
                return [
                    'id' => $role->id,
                    'name' => $role->name,
                    'display_name' => $role->getDisplayName($locale),
                    'description' => $role->getDescription($locale),
                    'is_active' => $role->is_active,
                    'permissions_count' => $role->permissions->count(),
                    'admins_count' => $role->admins()->count(),
                    'created_at' => $role->created_at,
                ];
            });

            return successResponse([
                'roles' => $data,
                'pagination' => [
                    'total' => $roles->total(),
                    'per_page' => $roles->perPage(),
                    'current_page' => $roles->currentPage(),
                    'last_page' => $roles->lastPage(),
                ],
            ], 'Roles retrieved successfully');

        } catch (\Exception $e) {
            return ErrorResponse::make('Failed to retrieve roles', null, 500);
        }
    }

    /**
     * Display the specified role
     */
    public function show(Request $request, $id): JsonResponse
    {
        try {
            $locale = $request->input('language', app()->getLocale());
            $role = Role::with('permissions')->findOrFail($id);

            $permissions = $role->permissions->map(function ($permission) use ($locale) {
                return [
                    'id' => $permission->id,
                    'name' => $permission->name,
                    'display_name' => $permission->getDisplayName($locale),
                    'group' => $permission->group,
                ];
            });

            return successResponse([
                'id' => $role->id,
                'name' => $role->name,
                'display_name_ar' => $role->display_name_ar,
                'display_name_en' => $role->display_name_en,
                'description_ar' => $role->description_ar,
                'description_en' => $role->description_en,
                'is_active' => $role->is_active,
                'permissions' => $permissions,
                'admins_count' => $role->admins()->count(),
                'created_at' => $role->created_at,
                'updated_at' => $role->updated_at,
            ], 'Role retrieved successfully');

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ErrorResponse::make('Role not found', null, 404);
        } catch (\Exception $e) {
            return ErrorResponse::make('Failed to retrieve role', null, 500);
        }
    }

    /**
     * Store a newly created role
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|unique:roles,name|max:255',
                'display_name_ar' => 'required|string|max:255',
                'display_name_en' => 'required|string|max:255',
                'description_ar' => 'nullable|string',
                'description_en' => 'nullable|string',
                'is_active' => 'boolean',
                'permission_ids' => 'nullable|array',
                'permission_ids.*' => 'exists:permissions,id',
            ]);

            if ($validator->fails()) {
                return ErrorResponse::make('Validation failed', $validator->errors(), 422);
            }

            DB::beginTransaction();

            $role = Role::create([
                'name' => $request->input('name'),
                'display_name_ar' => $request->input('display_name_ar'),
                'display_name_en' => $request->input('display_name_en'),
                'description_ar' => $request->input('description_ar'),
                'description_en' => $request->input('description_en'),
                'is_active' => $request->input('is_active', true),
            ]);

            // Attach permissions
            if ($request->has('permission_ids')) {
                $role->permissions()->attach($request->input('permission_ids'));
            }

            DB::commit();

            $locale = $request->input('language', app()->getLocale());

            return successResponse([
                'id' => $role->id,
                'name' => $role->name,
                'display_name' => $role->getDisplayName($locale),
                'description' => $role->getDescription($locale),
                'is_active' => $role->is_active,
                'permissions_count' => $role->permissions()->count(),
            ], 'Role created successfully', 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return ErrorResponse::make('Failed to create role', null, 500);
        }
    }

    /**
     * Update the specified role
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $role = Role::findOrFail($id);

            // Prevent editing super_admin role
            if ($role->name === 'super_admin') {
                $locale = $request->input('language', app()->getLocale());
                $message = $locale === 'ar'
                    ? 'لا يمكن تعديل دور المدير العام'
                    : 'Cannot edit super admin role';

                return ErrorResponse::make($message, null, 403);
            }

            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|string|unique:roles,name,'.$id.'|max:255',
                'display_name_ar' => 'sometimes|string|max:255',
                'display_name_en' => 'sometimes|string|max:255',
                'description_ar' => 'nullable|string',
                'description_en' => 'nullable|string',
                'is_active' => 'boolean',
                'permission_ids' => 'nullable|array',
                'permission_ids.*' => 'exists:permissions,id',
            ]);

            if ($validator->fails()) {
                return ErrorResponse::make('Validation failed', $validator->errors(), 422);
            }

            DB::beginTransaction();

            $role->update($request->only([
                'name',
                'display_name_ar',
                'display_name_en',
                'description_ar',
                'description_en',
                'is_active',
            ]));

            // Update permissions
            if ($request->has('permission_ids')) {
                $role->permissions()->sync($request->input('permission_ids'));
            }

            DB::commit();

            $locale = $request->input('language', app()->getLocale());

            return successResponse([
                'id' => $role->id,
                'name' => $role->name,
                'display_name' => $role->getDisplayName($locale),
                'description' => $role->getDescription($locale),
                'is_active' => $role->is_active,
                'permissions_count' => $role->permissions()->count(),
            ], 'Role updated successfully');

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ErrorResponse::make('Role not found', null, 404);
        } catch (\Exception $e) {
            DB::rollBack();

            return ErrorResponse::make('Failed to update role', null, 500);
        }
    }

    /**
     * Remove the specified role
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        try {
            $role = Role::findOrFail($id);

            // Prevent deleting super_admin role
            if ($role->name === 'super_admin') {
                $locale = $request->input('language', app()->getLocale());
                $message = $locale === 'ar'
                    ? 'لا يمكن حذف دور المدير العام'
                    : 'Cannot delete super admin role';

                return ErrorResponse::make($message, null, 403);
            }

            // Check if role has admins
            if ($role->admins()->count() > 0) {
                $locale = $request->input('language', app()->getLocale());
                $message = $locale === 'ar'
                    ? 'لا يمكن حذف دور مرتبط بمشرفين'
                    : 'Cannot delete role with assigned admins';

                return ErrorResponse::make($message, null, 400);
            }

            $role->delete();

            return successResponse(null, 'Role deleted successfully');

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ErrorResponse::make('Role not found', null, 404);
        } catch (\Exception $e) {
            return ErrorResponse::make('Failed to delete role', null, 500);
        }
    }

    /**
     * Get all permissions grouped by category
     */
    public function permissions(Request $request): JsonResponse
    {
        try {
            $locale = $request->input('language', app()->getLocale());
            $permissions = Permission::getAllGrouped($locale);

            return successResponse($permissions, 'Permissions retrieved successfully');

        } catch (\Exception $e) {
            return ErrorResponse::make('Failed to retrieve permissions', null, 500);
        }
    }
}
