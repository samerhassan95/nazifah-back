<?php

namespace Modules\Vendor\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckVendorPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $employee = $request->user('vendor');

        if (! $employee) {
            return errorResponse(__('auth.unauthenticated'), null, 401);
        }

        $branchId = $request->route('branchId')
            ?? $request->route('branch_id')
            ?? ($request->filled('branch_id') ? (int) $request->input('branch_id') : null);

        if (! $employee->hasVendorPermission($permission, $branchId)) {
            return errorResponse(__('vendor.unauthorized_action'), null, 403);
        }

        return $next($request);
    }
}
