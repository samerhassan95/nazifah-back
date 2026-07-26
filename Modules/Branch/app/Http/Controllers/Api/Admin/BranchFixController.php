<?php

namespace Modules\Branch\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Branch\Models\Branch;
use Modules\Vendor\Models\Vendor;

class BranchFixController extends Controller
{
    /**
     * Get branches without vendors
     */
    public function getBranchesWithoutVendors(): JsonResponse
    {
        $branches = Branch::whereNull('vendor_id')
            ->get()
            ->map(function ($branch) {
                return [
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'is_active' => $branch->is_active,
                    'created_at' => $branch->created_at->toDateTimeString(),
                ];
            });

        $vendors = Vendor::where('is_active', true)
            ->where('is_banned', false)
            ->get()
            ->map(function ($vendor) {
                return [
                    'id' => $vendor->id,
                    'name' => $vendor->name,
                    'is_active' => $vendor->is_active,
                ];
            });

        return successResponse([
            'branches_without_vendors' => $branches,
            'available_vendors' => $vendors,
        ], 'Data retrieved successfully');
    }

    /**
     * Assign vendor to branch
     */
    public function assignVendorToBranch(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'branch_id' => ['required', 'exists:branches,id'],
            'vendor_id' => ['required', 'exists:vendors,id'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $branch = Branch::find($request->branch_id);
        $vendor = Vendor::find($request->vendor_id);

        if (! $vendor->is_active) {
            return errorResponse('Vendor is not active', 400);
        }

        if ($vendor->is_banned) {
            return errorResponse('Vendor is banned', 400);
        }

        $branch->vendor_id = $request->vendor_id;
        $branch->save();

        return successResponse([
            'branch' => [
                'id' => $branch->id,
                'name' => $branch->name,
                'vendor_id' => $branch->vendor_id,
            ],
            'vendor' => [
                'id' => $vendor->id,
                'name' => $vendor->name,
            ],
        ], 'Vendor assigned to branch successfully');
    }

    /**
     * Assign all branches without vendors to a specific vendor
     */
    public function assignAllBranchesToVendor(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'vendor_id' => ['required', 'exists:vendors,id'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $vendor = Vendor::find($request->vendor_id);

        if (! $vendor->is_active) {
            return errorResponse('Vendor is not active', 400);
        }

        if ($vendor->is_banned) {
            return errorResponse('Vendor is banned', 400);
        }

        $branches = Branch::whereNull('vendor_id')->get();

        if ($branches->isEmpty()) {
            return successResponse([], 'No branches without vendors found');
        }

        $updated = [];
        foreach ($branches as $branch) {
            $branch->vendor_id = $request->vendor_id;
            $branch->save();
            $updated[] = [
                'id' => $branch->id,
                'name' => $branch->name,
            ];
        }

        return successResponse([
            'updated_branches' => $updated,
            'count' => count($updated),
            'vendor' => [
                'id' => $vendor->id,
                'name' => $vendor->name,
            ],
        ], "Assigned {count($updated)} branches to vendor successfully");
    }
}
