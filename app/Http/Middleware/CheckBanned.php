<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Admin\Models\Admin;
use Symfony\Component\HttpFoundation\Response;

class CheckBanned
{
    /**
     * Handle an incoming request.
     * Check if the authenticated user (client or vendor) is banned.
     * NOTE: Admin users are excluded from this check as they don't have is_banned property.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Skip check for Admin users - they don't have is_banned property
        if ($user instanceof Admin) {
            return $next($request);
        }

        // Only check is_banned for non-admin users (clients, vendors, etc.)
        if ($user && property_exists($user, 'is_banned') && $user->is_banned) {
            // Revoke current token
            $user->currentAccessToken()->delete();

            return response()->json([
                'success' => false,
                'message' => __('auth.account_banned'),
                'data' => [
                    'is_banned' => true,
                    'ban_reason' => $user->ban_reason,
                    'banned_at' => $user->banned_at?->toDateTimeString(),
                ],
            ], 403);
        }

        return $next($request);
    }
}
