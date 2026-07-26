<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ErrorResponse;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Modules\Admin\Http\Requests\StoreAdminRequest;
use Modules\Admin\Http\Resources\AdminResource;
use Modules\Admin\Models\Admin;
use Modules\Admin\Services\AdminService;

class AdminController extends Controller
{
    public function __construct(
        private AdminService $adminService
    ) {}

    /**
     * Display a listing of admins.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $filters = [
                'search' => $request->search,
                'sort_by' => $request->input('sort_by', 'created_at'),
                'sort_order' => $request->input('sort_order', 'desc'),
            ];

            $admins = $this->adminService->getAllPaginated(
                $filters,
                $request->input('per_page', 15)
            );

            return successResponse(
                AdminResource::collection($admins),
                __('admin::messages.admins_retrieved')
            );

        } catch (\Exception $e) {
            return ErrorResponse::make(__('admin::messages.admins_retrieve_failed'), null, 500);
        }
    }

    /**
     * Store a newly created admin.
     */
    public function store(StoreAdminRequest $request): JsonResponse
    {
        try {
            $admin = $this->adminService->create($request->validated());

            return successResponse(new AdminResource($admin), __('admin::messages.admin_created'), 201);

        } catch (QueryException $e) {
            $message = $e->getMessage();
            $errors = [];

            // Detect duplicate entry for common fields
            if (stripos($message, 'email') !== false) {
                $errors['email'] = [__('admin::messages.email_taken') ?: 'The email has already been taken.'];
            }
            if (stripos($message, 'phone') !== false) {
                $errors['phone'] = [__('admin::messages.phone_taken') ?: 'The phone has already been taken.'];
            }

            if (empty($errors)) {
                $errors['error'] = [$message];
            }

            return ErrorResponse::make(__('admin::messages.admin_create_failed'), $errors, 422, $request);

        } catch (\Exception $e) {
            return ErrorResponse::make(__('admin::messages.admin_create_failed'), null, 500, $request);
        }
    }

    /**
     * Display the specified admin.
     */
    public function show($id): JsonResponse
    {
        try {
            $admin = Admin::findOrFail($id);

            return successResponse(new AdminResource($admin), __('admin::messages.admin_retrieved'));

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ErrorResponse::make(__('admin::auth.admin_not_found'), null, 404);
        } catch (\Exception $e) {
            return ErrorResponse::make(__('admin::messages.admin_retrieve_failed'), null, 500);
        }
    }

    /**
     * Update the specified admin.
     */
    public function update(Request $request, $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'unique:admins,email,'.$id],
            'phone' => ['sometimes', 'string', 'regex:/^\+?[0-9]{10,15}$/', 'unique:admins,phone,'.$id],
            'password' => ['sometimes', 'string', 'min:6'],
            'is_verified' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return ErrorResponse::make(__('admin::auth.validation_error'), $validator->errors(), 422);
        }

        try {
            $admin = Admin::findOrFail($id);

            $data = [];
            if ($request->has('name')) {
                $data['name'] = $request->name;
            }
            if ($request->has('email')) {
                $data['email'] = $request->email;
            }
            if ($request->has('phone')) {
                $data['phone'] = $request->phone;
            }
            if ($request->has('password')) {
                $data['password'] = Hash::make($request->password);
            }
            if ($request->has('is_verified')) {
                $data['is_verified'] = $request->is_verified;
            }

            $admin->update($data);

            return successResponse(new AdminResource($admin->fresh()), __('admin::messages.admin_updated'));

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ErrorResponse::make(__('admin::auth.admin_not_found'), null, 404);
        } catch (\Exception $e) {
            return ErrorResponse::make(__('admin::messages.admin_update_failed'), null, 500);
        }
    }

    /**
     * Remove the specified admin.
     */
    public function destroy($id): JsonResponse
    {
        try {
            $admin = Admin::findOrFail($id);

            // Prevent deleting yourself
            if ($admin->id === auth()->id()) {
                return ErrorResponse::make(__('admin::messages.cannot_delete_self'), null, 403);
            }

            $admin->delete();

            return successResponse(null, __('admin::messages.admin_deleted'));

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ErrorResponse::make(__('admin::auth.admin_not_found'), null, 404);
        } catch (\Exception $e) {
            return ErrorResponse::make(__('admin::messages.admin_delete_failed'), null, 500);
        }
    }
}
