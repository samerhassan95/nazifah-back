<?php

namespace Modules\Order\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event handler mappings for the application.
     *
     * Intentionally EMPTY. Listeners are auto-discovered from app/Listeners — each
     * listener's handle() type-hint maps it to its event (the same convention every
     * other module uses, via $shouldDiscoverEvents below). Declaring an explicit
     * $listen here on top of discovery registered each OrderStatusChanged listener
     * TWICE, so capture, void/refund, payout distribution and status notifications all
     * fired twice per transition. (Discovery already covers SendOrderStatusNotification,
     * HandlePaymentCapture, HandlePaymentDistribution, HandleOrderCancellationRefund and
     * SendDriverAssignmentNotification.)
     *
     * @var array<string, array<int, string>>
     */
    protected $listen = [];

    /**
     * Indicates if events should be discovered.
     *
     * @var bool
     */
    protected static $shouldDiscoverEvents = true;
}
