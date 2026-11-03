<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ErrorResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Admin\Models\AdminSetting;

class AdminSmartOrderSettingController extends Controller
{
    private const RANKING_CRITERIA = ['last_ordered', 'distance', 'rating'];

    /**
     * Get smart order settings
     */
    public function index(): JsonResponse
    {
        try {
            return successResponse($this->currentSettings(), 'Smart order settings retrieved successfully');
        } catch (\Exception $e) {
            return ErrorResponse::make(adminErrorMessage('Failed to retrieve smart order settings', $e), null, 500);
        }
    }

    /**
     * Update smart order settings
     */
    public function update(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'claude_api_key' => ['nullable', 'string', 'max:255'],
            'daily_message_limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'laundry_ranking' => ['nullable', 'array', 'min:1'],
            'laundry_ranking.*' => ['string', 'in:'.implode(',', self::RANKING_CRITERIA)],
        ]);

        if ($validator->fails()) {
            return ErrorResponse::make('Validation failed', $validator->errors(), 422);
        }

        try {
            if ($request->has('claude_api_key')) {
                AdminSetting::setValue('smart_order_claude_api_key', $request->claude_api_key);
            }

            if ($request->has('daily_message_limit')) {
                AdminSetting::setValue('smart_order_daily_message_limit', $request->daily_message_limit, AdminSetting::TYPE_NUMBER);
            }

            if ($request->has('laundry_ranking')) {
                AdminSetting::setValue('smart_order_laundry_ranking', $request->laundry_ranking, AdminSetting::TYPE_JSON);
            }

            return successResponse($this->currentSettings(), 'Smart order settings updated successfully');
        } catch (\Exception $e) {
            return ErrorResponse::make(adminErrorMessage('Failed to update smart order settings', $e), null, 500);
        }
    }

    private function currentSettings(): array
    {
        return [
            'claude_api_key' => AdminSetting::getValue('smart_order_claude_api_key'),
            'daily_message_limit' => (int) AdminSetting::getValue('smart_order_daily_message_limit', 30),
            'laundry_ranking' => AdminSetting::getValue('smart_order_laundry_ranking', ['last_ordered', 'distance']),
            'available_ranking_criteria' => self::RANKING_CRITERIA,
        ];
    }
}
