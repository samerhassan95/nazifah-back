<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ErrorResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Client\Models\Client;
use Modules\Driver\Models\Driver;
use Modules\Vendor\Models\Vendor;

class AdminBanController extends Controller
{
    /**
     * Ban a user (client, driver, or vendor)
     * POST /api/admin/ban
     */
    public function ban(Request $request): JsonResponse
    {
        $request->validate([
            'user_type' => 'required|string|in:client,driver,vendor',
            'user_id' => 'required|integer',
            'reason' => 'nullable|string|max:500',
        ]);

        $userType = $request->user_type;
        $userId = $request->user_id;
        $reason = $request->input('reason');

        $user = $this->findUser($userType, $userId);

        if (! $user) {
            return ErrorResponse::make(
                ucfirst($userType).' not found',
                null,
                404
            );
        }

        $user->update([
            'is_banned' => true,
            'ban_reason' => $reason,
            'banned_at' => now(),
        ]);

        return successResponse(
            [
                'user_type' => $userType,
                'user_id' => $userId,
                'is_banned' => true,
                'ban_reason' => $reason,
                'banned_at' => $user->banned_at->toDateTimeString(),
            ],
            ucfirst($userType).' banned successfully'
        );
    }

    /**
     * Unban a user (client, driver, or vendor)
     * POST /api/admin/unban
     */
    public function unban(Request $request): JsonResponse
    {
        $request->validate([
            'user_type' => 'required|string|in:client,driver,vendor',
            'user_id' => 'required|integer',
        ]);

        $userType = $request->user_type;
        $userId = $request->user_id;

        $user = $this->findUser($userType, $userId);

        if (! $user) {
            return ErrorResponse::make(
                ucfirst($userType).' not found',
                null,
                404
            );
        }

        $user->update([
            'is_banned' => false,
            'ban_reason' => null,
            'banned_at' => null,
        ]);

        return successResponse(
            [
                'user_type' => $userType,
                'user_id' => $userId,
                'is_banned' => false,
            ],
            ucfirst($userType).' unbanned successfully'
        );
    }

    /**
     * Get ban status for a user
     * GET /api/admin/ban-status
     */
    public function getBanStatus(Request $request): JsonResponse
    {
        $request->validate([
            'user_type' => 'required|string|in:client,driver,vendor',
            'user_id' => 'required|integer',
        ]);

        $userType = $request->user_type;
        $userId = $request->user_id;

        $user = $this->findUser($userType, $userId);

        if (! $user) {
            return ErrorResponse::make(
                ucfirst($userType).' not found',
                null,
                404
            );
        }

        return successResponse([
            'user_type' => $userType,
            'user_id' => $userId,
            'is_banned' => (bool) ($user->is_banned ?? false),
            'ban_reason' => $user->ban_reason,
            'banned_at' => $user->banned_at?->toDateTimeString(),
        ], 'Ban status retrieved successfully');
    }

    /**
     * Get list of all banned users
     * GET /api/admin/banned-users
     */
    public function getBannedUsers(Request $request): JsonResponse
    {
        $request->validate([
            'user_type' => 'nullable|string|in:client,driver,vendor,all',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $userType = $request->input('user_type', 'all');
        $perPage = $request->input('per_page', 20);

        $bannedUsers = [];

        if ($userType === 'all' || $userType === 'client') {
            $clients = Client::where('is_banned', true)
                ->select('id', 'phone', 'full_name', 'email', 'ban_reason', 'banned_at')
                ->get()
                ->map(function ($client) {
                    return [
                        'user_type' => 'client',
                        'id' => $client->id,
                        'phone' => $client->phone,
                        'name' => $client->full_name,
                        'email' => $client->email,
                        'ban_reason' => $client->ban_reason,
                        'banned_at' => $client->banned_at?->toDateTimeString(),
                    ];
                });
            $bannedUsers = array_merge($bannedUsers, $clients->toArray());
        }

        if ($userType === 'all' || $userType === 'driver') {
            $drivers = Driver::where('is_banned', true)
                ->select('id', 'phone', 'full_name', 'email', 'ban_reason', 'banned_at')
                ->get()
                ->map(function ($driver) {
                    return [
                        'user_type' => 'driver',
                        'id' => $driver->id,
                        'phone' => $driver->phone,
                        'name' => $driver->full_name,
                        'email' => $driver->email,
                        'ban_reason' => $driver->ban_reason,
                        'banned_at' => $driver->banned_at?->toDateTimeString(),
                    ];
                });
            $bannedUsers = array_merge($bannedUsers, $drivers->toArray());
        }

        if ($userType === 'all' || $userType === 'vendor') {
            $vendors = Vendor::where('is_banned', true)
                ->select('id', 'phone', 'name', 'email', 'ban_reason', 'banned_at')
                ->get()
                ->map(function ($vendor) {
                    return [
                        'user_type' => 'vendor',
                        'id' => $vendor->id,
                        'phone' => $vendor->phone,
                        'name' => $vendor->name,
                        'email' => $vendor->email,
                        'ban_reason' => $vendor->ban_reason,
                        'banned_at' => $vendor->banned_at?->toDateTimeString(),
                    ];
                });
            $bannedUsers = array_merge($bannedUsers, $vendors->toArray());
        }

        // Sort by banned_at descending
        usort($bannedUsers, function ($a, $b) {
            return strtotime($b['banned_at']) - strtotime($a['banned_at']);
        });

        return successResponse([
            'banned_users' => $bannedUsers,
            'total' => count($bannedUsers),
        ], 'Banned users retrieved successfully');
    }

    /**
     * Find user by type and ID
     */
    private function findUser(string $userType, int $userId)
    {
        return match ($userType) {
            'client' => Client::find($userId),
            'driver' => Driver::find($userId),
            'vendor' => Vendor::find($userId),
            default => null,
        };
    }
}
