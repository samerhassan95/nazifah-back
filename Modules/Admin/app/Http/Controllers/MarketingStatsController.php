<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Discount\Models\Discount;
use Modules\Notification\Models\MarketingNotification;
use Modules\Whatsapp\Models\WhatsappCampaign;

class MarketingStatsController extends Controller
{
    /**
     * Get overall marketing statistics
     * GET /api/v1/admin/marketing/stats
     */
    public function index(): JsonResponse
    {
        // 1. Discount Codes Stats
        $discountsTotal = Discount::count();
        $discountsActive = Discount::active()->count();
        $discountsExpired = Discount::expired()->count();
        $discountsTotalUsed = Discount::sum('used_count');
        $discountsTotalAmount = 0; // Replace with actual value if available

        // 2. Notifications Stats
        $notificationsTotal = MarketingNotification::count();
        $notificationsSent = MarketingNotification::where('status', 'sent')->count();
        $notificationsDraft = MarketingNotification::where('status', 'draft')->count();
        $notificationsTotalRecipients = MarketingNotification::sum('total_recipients');
        $notificationsTotalRead = MarketingNotification::sum('read_count');
        $notificationsSentCount = MarketingNotification::sum('sent_count');

        $notificationsAvgReadRate = 0;
        if ($notificationsSentCount > 0) {
            $notificationsAvgReadRate = round(($notificationsTotalRead / $notificationsSentCount) * 100, 2);
        }

        // 3. Whatsapp Campaigns Stats
        $whatsappTotal = WhatsappCampaign::count();
        $whatsappSent = WhatsappCampaign::where('status', 'sent')->count();
        $whatsappScheduled = WhatsappCampaign::where('status', 'scheduled')->count();
        $whatsappTotalRecipients = WhatsappCampaign::sum('total_recipients');
        $whatsappDeliveredCount = WhatsappCampaign::sum('delivered_count');
        $whatsappReadCount = WhatsappCampaign::sum('read_count');

        $whatsappAvgDeliveryRate = 0;
        if ($whatsappTotalRecipients > 0) {
            $whatsappAvgDeliveryRate = round(($whatsappDeliveredCount / $whatsappTotalRecipients) * 100, 2);
        }

        $whatsappAvgReadRate = 0;
        if ($whatsappDeliveredCount > 0) {
            $whatsappAvgReadRate = round(($whatsappReadCount / $whatsappDeliveredCount) * 100, 2);
        }

        return successResponse([
            'discount_codes' => [
                'total' => $discountsTotal,
                'active' => $discountsActive,
                'expired' => $discountsExpired,
                'total_used' => $discountsTotalUsed,
                'total_discount_amount' => $discountsTotalAmount,
            ],
            'notifications' => [
                'total' => $notificationsTotal,
                'sent' => $notificationsSent,
                'draft' => $notificationsDraft,
                'total_recipients' => $notificationsTotalRecipients,
                'total_read' => $notificationsTotalRead,
                'avg_read_rate' => $notificationsAvgReadRate.'%',
            ],
            'whatsapp_campaigns' => [
                'total' => $whatsappTotal,
                'sent' => $whatsappSent,
                'scheduled' => $whatsappScheduled,
                'total_recipients' => $whatsappTotalRecipients,
                'avg_delivery_rate' => $whatsappAvgDeliveryRate.'%',
                'avg_read_rate' => $whatsappAvgReadRate.'%',
            ],
        ], 'Marketing statistics retrieved successfully');
    }
}
