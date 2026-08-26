<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ErrorResponse;
use App\Services\UploadFilesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Modules\Admin\Http\Resources\AdminUserResource;
use Modules\Admin\Models\Admin;

class AdminUserController extends Controller
{
    public function __construct(
        private UploadFilesService $uploadService
    ) {}

    /**
     * Display a listing of admin users
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Admin::query();

            // Search
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            }

            // Filter by status
            if ($request->has('status')) {
                $isActive = $request->status === 'active';
                $query->where('is_verified', $isActive);
            }

            // Sort
            $sortBy = $request->input('sort_by', 'created_at');
            $sortOrder = $request->input('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            $perPage = $request->input('per_page', 15);
            $users = $query->paginate($perPage);

            return successResponse(
                AdminUserResource::collection($users),
                'Users retrieved successfully'
            );

        } catch (\Exception $e) {
            return ErrorResponse::make('Failed to retrieve users', null, 500);
        }
    }

    /**
     * Store a newly created admin user
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif', 'max:2048'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:admins,email'],
            'phone' => ['required', 'string', 'regex:/^\+?[0-9]{10,15}$/', 'unique:admins,phone'],
            'password' => ['required', 'string', 'min:6'],
            'confirm_password' => ['required', 'same:password'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'in:show,edit,delete'],
        ]);

        if ($validator->fails()) {
            return ErrorResponse::make('Validation failed', $validator->errors(), 422);
        }

        try {
            $data = [
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
                'password' => Hash::make($request->password),
                'is_verified' => true,
                'permissions' => $request->input('permissions', []),
            ];

            // Handle image upload
            if ($request->hasFile('image')) {
                $data['image'] = $this->uploadService->uploadFile($request->file('image'), 'admins');
            }

            $user = Admin::create($data);

            return successResponse(
                new AdminUserResource($user),
                'User created successfully',
                201
            );

        } catch (\Exception $e) {
            return ErrorResponse::make('Failed to create user', null, 500);
        }
    }

    /**
     * Display the specified admin user
     */
    public function show($id): JsonResponse
    {
        try {
            $user = Admin::findOrFail($id);

            return successResponse(
                new AdminUserResource($user),
                'User retrieved successfully'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ErrorResponse::make('User not found', null, 404);
        } catch (\Exception $e) {
            return ErrorResponse::make('Failed to retrieve user', null, 500);
        }
    }

    /**
     * Update the specified admin user
     */
    public function update(Request $request, $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif', 'max:2048'],
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'unique:admins,email,'.$id],
            'phone' => ['nullable', 'string', 'regex:/^\+?[0-9]{10,15}$/', 'unique:admins,phone,'.$id],
            'password' => ['nullable', 'string', 'min:6'],
            'confirm_password' => ['nullable', 'required_with:password', 'same:password'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'in:show,edit,delete'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
        ]);

        if ($validator->fails()) {
            return ErrorResponse::make('Validation failed', $validator->errors(), 422);
        }

        try {
            $user = Admin::findOrFail($id);

            $data = [];

            // Handle image upload
            if ($request->hasFile('image')) {
                $data['image'] = $this->uploadService->uploadFile($request->file('image'), 'admins');
            }

            if ($request->has('name')) {
                $data['name'] = $request->name;
            }

            if ($request->has('email')) {
                $data['email'] = $request->email;
            }

            if ($request->has('phone')) {
                $data['phone'] = $request->phone;
            }

            if ($request->filled('password')) {
                $data['password'] = Hash::make($request->password);
            }

            if ($request->has('status')) {
                $data['is_verified'] = $request->status === 'active';
            }

            if ($request->has('permissions')) {
                $data['permissions'] = $request->input('permissions', []);
            }

            $user->update($data);

            return successResponse(
                new AdminUserResource($user->fresh()),
                'User updated successfully'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ErrorResponse::make('User not found', null, 404);
        } catch (\Exception $e) {
            return ErrorResponse::make('Failed to update user', null, 500);
        }
    }

    /**
     * Remove the specified admin user
     */
    public function destroy($id): JsonResponse
    {
        try {
            $user = Admin::findOrFail($id);

            // Prevent deleting yourself
            if ($user->id === auth()->id()) {
                return ErrorResponse::make('Cannot delete your own account', null, 403);
            }

            $user->delete();

            return successResponse(null, 'User deleted successfully');

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ErrorResponse::make('User not found', null, 404);
        } catch (\Exception $e) {
            return ErrorResponse::make('Failed to delete user', null, 500);
        }
    }
}
