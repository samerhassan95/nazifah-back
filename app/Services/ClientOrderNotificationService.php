<?php

namespace App\Services;

use Modules\Order\Models\Order;

/**
 * @deprecated Use OrderNotificationService::sendToClient() instead.
 */
class ClientOrderNotificationService extends OrderNotificationService
{
    public function send(
        Order $order,
        string $titleAr,
        string $titleEn,
        string $bodyAr,
        string $bodyEn,
        string $type,
        array $extraData = []
    ): void {
        $this->sendToClient($order, $titleAr, $titleEn, $bodyAr, $bodyEn, $type, $extraData);
    }
}
