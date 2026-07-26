<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Driver\Models\Driver;
use Modules\Order\Models\Order;

class DriverAssigned
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Order $order,
        public readonly Driver $driver,
        public readonly string $assignmentType, // 'pickup' or 'delivery'
    ) {}
}
