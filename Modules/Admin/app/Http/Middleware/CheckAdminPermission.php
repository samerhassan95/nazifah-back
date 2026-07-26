<?php

namespace Modules\Admin\Http\Middleware;

use App\Http\Responses\ErrorResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckAdminPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $admin = $request->user('admin');

        if (! $admin) {
            return ErrorResponse::make('Unauthorized', null, 401);
        }

        // Super admin has all permissions
        if ($admin->isSuperAdmin()) {
            return $next($request);
        }

        // Check if admin has the required permission
        if (! $admin->hasPermission($permission)) {
            $locale = $request->input('language', app()->getLocale());
            $message = $locale === 'ar'
                ? 'ليس لديك صلاحية للوصول إلى هذا المورد'
                : 'You do not have permission to access this resource';

            return ErrorResponse::make($message, null, 403);
        }

        return $next($request);
    }
}
