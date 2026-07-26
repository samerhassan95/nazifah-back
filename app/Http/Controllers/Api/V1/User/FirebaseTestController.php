<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Services\FirebaseAdminService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FirebaseTestController extends Controller
{
    protected $firebase;

    public function __construct(FirebaseAdminService $firebase)
    {
        $this->firebase = $firebase;
    }

    /**
     * Update user's FCM token
     * POST /api/v1/user/fcm-token
     */
    public function updateFcmToken(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'fcm_token' => 'required|string',
            'device_id' => 'nullable|string',
            'device_type' => 'nullable|string|in:android,ios,web',
            'lang' => 'required|string|in:ar,en',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'code' => 422,
                'message' => __('validation.failed'),
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();

        // Add or update FCM token
        $fcmToken = $user->addFcmToken(
            $request->fcm_token,
            $request->device_id,
            $request->device_type,
            $request->input('lang')
        );

        return response()->json([
            'status' => true,
            'code' => 200,
            'message' => __('notification.fcm_token_updated'),
            'data' => [
                'id' => $fcmToken->id,
                'token' => $fcmToken->token,
                'device_id' => $fcmToken->device_id,
                'device_type' => $fcmToken->device_type,
                'lang' => $fcmToken->lang,
            ],
        ]);
    }

    /**
     * Get all FCM tokens for current user
     * GET /api/v1/user/fcm-tokens
     */
    public function getFcmTokens(Request $request)
    {
        $user = $request->user();

        $tokens = $user->fcmTokens()
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($token) {
                return [
                    'id' => $token->id,
                    'device_id' => $token->device_id,
                    'device_type' => $token->device_type,
                    'lang' => $token->lang,
                    'created_at' => $token->created_at->toIso8601String(),
                ];
            });

        return response()->json([
            'status' => true,
            'code' => 200,
            'message' => __('notification.tokens_retrieved'),
            'data' => [
                'tokens' => $tokens,
                'total' => $tokens->count(),
            ],
        ]);
    }

    /**
     * Delete FCM token
     * DELETE /api/v1/user/fcm-token/{tokenId}
     */
    public function deleteFcmToken(Request $request, $tokenId)
    {
        $user = $request->user();

        $token = $user->fcmTokens()->find($tokenId);

        if (! $token) {
            return response()->json([
                'status' => false,
                'code' => 404,
                'message' => __('notification.token_not_found'),
            ], 404);
        }

        $token->delete();

        return response()->json([
            'status' => true,
            'code' => 200,
            'message' => __('notification.token_deleted'),
        ]);
    }

    /**
     * Delete FCM token by device ID
     * DELETE /api/v1/user/fcm-token/device/{deviceId}
     */
    public function deleteFcmTokenByDevice(Request $request, $deviceId)
    {
        $user = $request->user();

        $deleted = $user->removeFcmTokenByDevice($deviceId);

        if (! $deleted) {
            return response()->json([
                'status' => false,
                'code' => 404,
                'message' => __('notification.token_not_found'),
            ], 404);
        }

        return response()->json([
            'status' => true,
            'code' => 200,
            'message' => __('notification.token_deleted'),
        ]);
    }
}
