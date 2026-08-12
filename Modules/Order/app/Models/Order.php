<?php

namespace Modules\Order\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Traits\Cacheable;
use App\Traits\HasSoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Modules\Address\Models\Address;
use Modules\Branch\Models\Branch;
use Modules\Client\Models\Client;
use Modules\Discount\Models\Discount;
use Modules\Driver\Models\Driver;
use Modules\Vendor\Models\Vendor;

class Order extends Model
{
    use Cacheable;
    use HasFactory;
    use HasSoftDeletes;

    protected $appends = [
        'status_label',
    ];

    protected $fillable = [
        'client_id',
        'branch_id',
        'driver_id',
        'pickup_driver_id',
        'delivery_driver_id',
        'order_number',
        'status',
        'vendor_reviewed',
        'vendor_reviewed_at',
        'vendor_review_notes',
        'client_approved',
        'client_approved_at',
        'original_total_amount',
        'original_final_amount',
        'total_amount',
        'discount_amount',
        'discount_id',
        'tax_amount',
        'delivery_fee',
        'final_amount',
        'amount_remaining',
        'pickup_address_id',
        'delivery_address_id',
        'pickup_time',
        'estimated_delivery_time',
        'actual_delivery_time',
        'notes',
        'client_postpone_reason',
        'client_postponed_at',
        'client_visit_confirmed_at',
        'driver_pickup_notified_client_at',
        'client_pickup_visit_confirmed_at',
        'client_delivery_visit_confirmed_at',
        'client_pickup_handoff_at',
        'client_delivery_handoff_at',
        'can_confirm_pickup_from_driver',
        'can_confirm_handover_to_delivery',
        'vendor_handed_to_delivery_at',
        'vendor_pickup_received_at',
        'vendor_delivery_ready_at',
        'vendor_client_delivery_handoff_at',
        'attachments',
        'qr_code',
        'rating',
        'review',
        'cancelled_reason',
        'cancelled_at',
        'payment_method',
        'payment_methods',
        'pickup_at_vendor',
        'delivery_at_vendor',
        'distance',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'delivery_fee' => 'decimal:2',
        'final_amount' => 'decimal:2',
        'amount_remaining' => 'decimal:2',
        'original_total_amount' => 'decimal:2',
        'original_final_amount' => 'decimal:2',
        'distance' => 'decimal:2',
        'pickup_time' => 'datetime',
        'estimated_delivery_time' => 'datetime',
        'actual_delivery_time' => 'datetime',
        'vendor_reviewed_at' => 'datetime',
        'client_approved_at' => 'datetime',
        'client_postponed_at' => 'datetime',
        'client_visit_confirmed_at' => 'datetime',
        'driver_pickup_notified_client_at' => 'datetime',
        'client_pickup_visit_confirmed_at' => 'datetime',
        'client_delivery_visit_confirmed_at' => 'datetime',
        'client_pickup_handoff_at' => 'datetime',
        'client_delivery_handoff_at' => 'datetime',
        'can_confirm_pickup_from_driver' => 'boolean',
        'can_confirm_handover_to_delivery' => 'boolean',
        'vendor_handed_to_delivery_at' => 'datetime',
        'vendor_pickup_received_at' => 'datetime',
        'vendor_delivery_ready_at' => 'datetime',
        'vendor_client_delivery_handoff_at' => 'datetime',
        'rating' => 'integer',
        'cancelled_at' => 'datetime',
        'pickup_at_vendor' => 'boolean',
        'delivery_at_vendor' => 'boolean',
        'vendor_reviewed' => 'boolean',
        'client_approved' => 'boolean',
        'attachments' => 'array',
        'payment_methods' => 'array',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Get the vendor through the branch relationship
     */
    public function vendor(): HasOneThrough
    {
        return $this->hasOneThrough(
            Vendor::class,
            Branch::class,
            'id',
            'id',
            'branch_id',
            'vendor_id'
        );
    }

    public function resolveVendorId(): ?int
    {
        if ($this->relationLoaded('branch')) {
            return $this->branch?->vendor_id ? (int) $this->branch->vendor_id : null;
        }

        if ($this->branch_id) {
            $this->loadMissing('branch');

            return $this->branch?->vendor_id ? (int) $this->branch->vendor_id : null;
        }

        return null;
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function pickupDriver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'pickup_driver_id');
    }

    public function deliveryDriver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'delivery_driver_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(OrderStatusLog::class);
    }

    public function driverTrips(): HasMany
    {
        return $this->hasMany(OrderDriverTrip::class);
    }

    public function pickupAddress(): BelongsTo
    {
        return $this->belongsTo(Address::class, 'pickup_address_id');
    }

    public function deliveryAddress(): BelongsTo
    {
        return $this->belongsTo(Address::class, 'delivery_address_id');
    }

    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }

    /**
     * Coupon payload for order APIs (null when no coupon was saved on the order).
     *
     * @return array<string, mixed>|null
     */
    public function couponForApi(?string $lang = null): ?array
    {
        $this->loadMissing('discount');
        $discount = $this->discount;
        if (! $discount) {
            return null;
        }

        $lang = $lang ?? app()->getLocale();

        return [
            'id' => $discount->id,
            'code' => $discount->code,
            'name' => method_exists($discount, 'getTranslation')
                ? $discount->getTranslation('name', $lang)
                : $discount->name,
            'description' => method_exists($discount, 'getTranslation')
                ? $discount->getTranslation('description', $lang)
                : ($discount->description ?? null),
            'type' => $discount->type,
            'discount_type' => $discount->discount_type,
            'value' => (float) $discount->value,
            'discount_amount' => (float) $this->discount_amount,
            'min_order_amount' => $discount->min_order_amount !== null ? (float) $discount->min_order_amount : null,
            'max_discount_amount' => $discount->max_discount_amount !== null ? (float) $discount->max_discount_amount : null,
        ];
    }

    /**
     * @return array{coupon_code: ?string, have_coupon: bool, coupon: ?array}
     */
    public function couponResponseFields(?string $lang = null): array
    {
        $coupon = $this->couponForApi($lang);

        return [
            'coupon_code' => $coupon['code'] ?? null,
            'have_coupon' => $coupon !== null,
            'coupon' => $coupon,
        ];
    }

    public function paymentTransactions(): HasMany
    {
        return $this->hasMany(\Modules\Payment\Models\PaymentTransaction::class);
    }

    public function invoice(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(\Modules\Invoice\Models\Invoice::class);
    }

    /**
     * Latest CHECKOUT payment transaction. Scoped to exclude additional-charge
     * (surcharge / split) legs so a pending surcharge never flips a paid order's
     * payment_status back to "pending".
     */
    public function latestPayment(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        // The is_additional_charge filter MUST live inside the one-of-many
        // aggregate subquery (closure form), not as a chained ->where() before
        // latestOfMany(). A chained where only filters the OUTER row: the inner
        // MAX(id) would still pick a later surcharge leg, the outer filter would
        // then drop it, and payment_status would wrongly read "pending".
        return $this->hasOne(\Modules\Payment\Models\PaymentTransaction::class)
            ->ofMany(['id' => 'max'], function ($query) {
                $query->where('is_additional_charge', false);
            });
    }

    /**
     * Surcharge / split-payment legs (items added after the order was placed,
     * or a second method covering part of the total).
     */
    public function additionalChargeTransactions(): HasMany
    {
        return $this->hasMany(\Modules\Payment\Models\PaymentTransaction::class)
            ->where('is_additional_charge', true);
    }

    /**
     * Split-payment ledger: one row per payment leg.
     */
    public function orderPayments(): HasMany
    {
        return $this->hasMany(\Modules\Order\Models\OrderPayment::class);
    }

    /**
     * Staged order-total increases awaiting surcharge payment.
     */
    public function modificationIntents(): HasMany
    {
        return $this->hasMany(\Modules\Order\Models\OrderModificationIntent::class);
    }

    /**
     * The single pending staged total increase, if any.
     */
    public function pendingModificationIntent(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        // Same one-of-many pitfall as latestPayment(): the status filter must be
        // inside the aggregate subquery, or the latest-by-id pick could land on a
        // resolved/expired intent and the outer status filter would null it out.
        return $this->hasOne(\Modules\Order\Models\OrderModificationIntent::class)
            ->ofMany(['id' => 'max'], function ($query) {
                $query->where('status', \Modules\Order\Models\OrderModificationIntent::STATUS_PENDING);
            });
    }

    /**
     * Client-facing payment status from the latest checkout payment transaction.
     *
     * A successful APS AUTHORIZATION is a paid order for display purposes (funds
     * are held; capture happens later), so the raw 'authorized' value is surfaced
     * as 'completed' — otherwise every consumer that checks for 'completed'/'paid'
     * would treat a successful auth as unpaid. Internal capture/void logic that
     * needs the un-normalized value must use {@see getRawPaymentStatusAttribute()}.
     */
    public function getPaymentStatusAttribute(): string
    {
        $raw = $this->raw_payment_status;

        return $raw === 'authorized' ? 'completed' : $raw;
    }

    /**
     * Raw status of the latest checkout payment transaction (e.g. 'authorized'
     * before capture). Use this — NOT payment_status — for capture/void decisions.
     */
    public function getRawPaymentStatusAttribute(): string
    {
        return $this->relationLoaded('latestPayment')
            ? ($this->relations['latestPayment']?->status ?? 'pending')
            : ($this->latestPayment?->status ?? 'pending');
    }

    /**
     * True when checkout payment already succeeded (card/wallet at order creation).
     * A partial refund (e.g. a vendor review reduced the order) does not un-pay the
     * order — the checkout payment still succeeded — so `partially_refunded` counts;
     * a fully `refunded` transaction does not.
     */
    public function isPaid(): bool
    {
        return $this->paymentTransactions()
            ->whereIn('status', ['completed', 'authorized', 'partially_refunded'])
            ->exists();
    }

    /**
     * True when the confirmation-time capture could not cover the full amount due
     * (Phase-2 Case B): the order still owes `amount_remaining` and must not be
     * treated as fully settled until the customer pays the remainder.
     */
    public function isAwaitingRemainingPayment(): bool
    {
        return (float) $this->amount_remaining > 0.005;
    }

    /**
     * True when client chose cash on delivery (payment at order completion).
     */
    public function isCashOnDelivery(): bool
    {
        return ($this->payment_method ?? null) === PaymentMethod::CASH_ON_DELIVERY->value;
    }

    /**
     * Get the localized label for the order status
     */
    public function getStatusLabelAttribute(): string
    {
        $status = OrderStatus::tryFrom($this->status);

        return $status ? $status->localizedLabel($this->payment_method) : $this->status;
    }

    /**
     * Localized label for payment_status (Accept-Language aware).
     */
    public function getPaymentStatusLabelAttribute(): string
    {
        return \App\Support\PaymentStatusPresenter::label($this->payment_status ?? 'pending');
    }

    /**
     * Get the color for the order status
     */
    public function getStatusColorAttribute(): string
    {
        $status = OrderStatus::tryFrom($this->status);

        return $status ? $status->color() : '#808080';
    }

    /**
     * Get the icon for the order status
     */
    public function getStatusIconAttribute(): string
    {
        $status = OrderStatus::tryFrom($this->status);

        return $status ? $status->icon() : '❓';
    }

    /**
     * Selected payment methods for this order (client input at checkout).
     *
     * @return list<string>
     */
    public function resolvedPaymentMethods(): array
    {
        if (is_array($this->payment_methods) && count($this->payment_methods) > 0) {
            return array_values($this->payment_methods);
        }

        if ($this->payment_method) {
            return [$this->payment_method];
        }

        return [];
    }

    /**
     * @return array{payment_method: ?string, payment_methods: list<string>}
     */
    public function paymentFieldsForApi(): array
    {
        return [
            'payment_method' => $this->payment_method,
            'payment_methods' => $this->resolvedPaymentMethods(),
        ];
    }

    /**
     * Merge newly used methods into the stored payment_methods list.
     *
     * @param  list<string>  $methods
     */
    public function mergePaymentMethods(array $methods): void
    {
        $methods = array_values(array_unique(array_filter(array_map('strval', $methods))));
        if ($methods === []) {
            return;
        }

        $merged = array_values(array_unique(array_merge($this->resolvedPaymentMethods(), $methods)));
        $this->forceFill(['payment_methods' => $merged])->save();
    }

    /**
     * Client visit response fields for API payloads (user, vendor, driver).
     */
    public function clientVisitResponseFields(): array
    {
        return [
            'client_postpone_reason' => $this->client_postpone_reason,
            'client_postponed_at' => $this->client_postponed_at?->toISOString(),
            'client_visit_confirmed_at' => $this->client_visit_confirmed_at?->toISOString(),
            'driver_pickup_notified_client_at' => $this->driver_pickup_notified_client_at?->toISOString(),
            'client_pickup_visit_confirmed_at' => $this->client_pickup_visit_confirmed_at?->toISOString(),
            'client_delivery_visit_confirmed_at' => $this->client_delivery_visit_confirmed_at?->toISOString(),
            'client_pickup_handoff_at' => $this->client_pickup_handoff_at?->toISOString(),
            'client_delivery_handoff_at' => $this->client_delivery_handoff_at?->toISOString(),
        ];
    }

    /**
     * Client + vendor handoff timestamps for API payloads.
     */
    public function handoffResponseFields(): array
    {
        return array_merge($this->clientVisitResponseFields(), [
            'vendor_pickup_received_at' => $this->vendor_pickup_received_at?->toISOString(),
            'vendor_delivery_ready_at' => $this->vendor_delivery_ready_at?->toISOString(),
            'vendor_handed_to_delivery_at' => $this->vendor_handed_to_delivery_at?->toISOString(),
            'vendor_client_delivery_handoff_at' => $this->vendor_client_delivery_handoff_at?->toISOString(),
        ]);
    }

    public function needsPickupDriver(): bool
    {
        return ! $this->pickup_at_vendor;
    }

    public function needsDeliveryDriver(): bool
    {
        return ! $this->delivery_at_vendor;
    }

    /**
     * Scope: orders assigned to this driver (as current, pickup, or delivery driver)
     */
    public function scopeForDriver($query, int $driverId)
    {
        return $query->where(function ($q) use ($driverId) {
            $q->where('driver_id', $driverId)
                ->orWhere('pickup_driver_id', $driverId)
                ->orWhere('delivery_driver_id', $driverId);
        });
    }

    /**
     * Scope: orders at a branch where this driver is the pickup or delivery driver.
     */
    public function scopeForDriverAtBranch($query, int $driverId, ?int $branchId)
    {
        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        return $query->where(function ($q) use ($driverId) {
            $q->where('pickup_driver_id', $driverId)
                ->orWhere('delivery_driver_id', $driverId);
        });
    }

    /**
     * Scope: in-progress orders for a driver (matches GET /driver/orders default).
     * Excludes *_ASSIGNED (new-orders API) and waiting_payment / pre-acceptance states.
     * Includes DRIVER_PICKUP_ACCEPTED so an accepted pickup does not disappear until
     * the driver moves to on_way / picked_up (same as DRIVER_DELIVERY_ACCEPTED).
     */
    public function scopeDriverCurrent($query, int $driverId)
    {
        return $query->where(function ($q) use ($driverId) {
            $q->where(function ($q2) use ($driverId) {
                $q2->where('pickup_driver_id', $driverId)
                    ->whereIn('status', [
                        OrderStatus::DRIVER_PICKUP_ACCEPTED->value,
                        OrderStatus::PAYMENT_CONFIRMED->value,
                        OrderStatus::ON_WAY_TO_PICKUP->value,
                        OrderStatus::PICKED_UP->value,
                    ]);
            })
                ->orWhere(function ($q2) use ($driverId) {
                    $q2->where('delivery_driver_id', $driverId)
                        ->whereIn('status', [
                            OrderStatus::DRIVER_DELIVERY_ACCEPTED->value,
                            OrderStatus::ON_WAY_TO_DELIVERY->value,
                            OrderStatus::WAITING_CLIENT_RECEIPT->value,
                        ]);
                });
        });
    }

    /**
     * Scope: new orders awaiting driver acceptance (matches GET /driver/home/new-orders).
     */
    public function scopeDriverNew($query, int $driverId)
    {
        return $query->where(function ($q) use ($driverId) {
            $q->where(function ($q2) use ($driverId) {
                $q2->where('pickup_driver_id', $driverId)
                    ->where('status', OrderStatus::DRIVER_PICKUP_ASSIGNED->value);
            })
                ->orWhere(function ($q2) use ($driverId) {
                    $q2->where('delivery_driver_id', $driverId)
                        ->where('status', OrderStatus::DRIVER_DELIVERY_ASSIGNED->value);
                });
        });
    }

    /**
     * Scope: orders the driver can act on now (new-orders + current-orders lists).
     */
    public function scopeDriverActive($query, int $driverId)
    {
        return $query->where(function ($q) use ($driverId) {
            $q->where(function ($q2) use ($driverId) {
                $q2->driverNew($driverId);
            })->orWhere(function ($q2) use ($driverId) {
                $q2->driverCurrent($driverId);
            });
        });
    }

    /**
     * Scope: active orders assigned to this driver at their branch, created today.
     */
    public function scopeDriverToday($query, int $driverId, ?int $branchId)
    {
        return $query->forDriverAtBranch($driverId, $branchId)
            ->whereDate('created_at', today())
            ->driverActive($driverId);
    }

    /**
     * Vendor tab: active orders (accepted, in progress). Excludes pending and finished,
     * except a pending order that already has a driver assigned — a client edit resets
     * status to pending for re-review but leaves the driver assignment in place, and it
     * must stay visible here rather than falling into the "new orders" tab (which only
     * shows driver-less pending orders).
     */
    public function scopeVendorCurrent($query)
    {
        return $query->where(function ($q) {
            $q->whereIn('status', OrderStatus::vendorCurrentStatusValues())
                ->orWhere(function ($q2) {
                    $q2->where('status', OrderStatus::COMPLETED->value)
                        ->where('delivery_at_vendor', true)
                        ->whereNull('client_delivery_handoff_at')
                        ->whereNull('vendor_client_delivery_handoff_at');
                })
                ->orWhere(function ($q3) {
                    $q3->where('status', OrderStatus::PENDING->value)
                        ->where(function ($q4) {
                            $q4->whereNotNull('driver_id')
                                ->orWhereNotNull('pickup_driver_id')
                                ->orWhereNotNull('delivery_driver_id');
                        });
                });
        });
    }

    /**
     * Vendor tab: finished orders only.
     */
    public function scopeVendorCompleted($query)
    {
        return $query->where(function ($q) {
            $q->where('status', OrderStatus::DELIVERED->value)
                ->orWhere(function ($q2) {
                    $q2->where('status', OrderStatus::COMPLETED->value)
                        ->where(function ($q3) {
                            $q3->where('delivery_at_vendor', false)
                                ->orWhereNotNull('client_delivery_handoff_at')
                                ->orWhereNotNull('vendor_client_delivery_handoff_at');
                        });
                });
        });
    }

    /**
     * Get the tax rate from admin settings
     */
    public static function getTaxRate(): float
    {
        return (float) (\Modules\Admin\Models\AdminSetting::getValue('tax_percentage', 14));
    }

    /**
     * Calculate effective price for a single order item using branch-specific pricing.
     * Only counts accepted additions in the unit price.
     *
     * Pricing model: service_piece is the source of truth for the combined
     * piece+service price. piece_price is always 0 in the new format and the
     * combined price lives in service_price.
     *
     * Historical orders stored under the old format (piece_price > 0) are
     * returned verbatim from stored values so historical totals stay stable.
     *
     * @return array{piece_price: float, service_price: float, additions_total: float, unit_price: float, total_price: float}
     */
    public function getEffectiveItemPrice(OrderItem $item): array
    {
        $branchId = $this->branch_id;

        $storedPiecePrice = (float) $item->piece_price;
        $storedServicePrice = (float) $item->service_price;

        if ($storedPiecePrice > 0) {
            // Old-format historical order: trust stored values.
            $piecePrice = $storedPiecePrice;
            $servicePrice = $storedServicePrice;
        } else {
            // New-format order: piece_price is 0, service_price is the combined value.
            $piecePrice = 0.0;
            $servicePrice = $item->piece && $item->service
                ? (float) $item->service->getPriceForPieceAtBranch($item->piece_id, $branchId)
                : $storedServicePrice;
        }

        // Load additionalServicesPivot if not already loaded
        $pivots = $item->relationLoaded('additionalServicesPivot')
            ? $item->additionalServicesPivot
            : $item->additionalServicesPivot()->with('serviceAddition')->get();

        $acceptedAdditionsTotal = 0.0;
        foreach ($pivots as $pivot) {
            // Match show/tracking: treat unset status as accepted; only skip rejected.
            if (($pivot->vendor_status ?? 'accepted') === 'rejected') {
                continue;
            }
            $additionPrice = \App\Support\OrderItemDisplayNames::storedAdditionalServiceUnitPrice($pivot);
            // pivot.quantity is units of this addition on the line (already totalled).
            $acceptedAdditionsTotal += $additionPrice * (int) ($pivot->quantity ?? 1);
        }

        $quantity = max(1, (int) $item->quantity);
        // Do NOT fold additions into per-unit then multiply by qty — pivot qty
        // already scales additions (bundled historical rows would double-count).
        $totalPrice = round((($piecePrice + $servicePrice) * $quantity) + $acceptedAdditionsTotal, 2);
        $unitPrice = round($totalPrice / $quantity, 2);

        return [
            'piece_price' => $piecePrice,
            'service_price' => $servicePrice,
            'additions_total' => $acceptedAdditionsTotal,
            'unit_price' => $unitPrice,
            'total_price' => $totalPrice,
        ];
    }

    /**
     * Get the effective subtotal (sum of accepted items only, branch-specific prices).
     */
    public function getEffectiveSubtotal(): float
    {
        $items = $this->relationLoaded('items') ? $this->items : $this->items()->get();
        $subtotal = 0.0;

        foreach ($items as $item) {
            if (($item->vendor_status ?? 'accepted') === 'rejected') {
                continue;
            }
            $pricing = $this->getEffectiveItemPrice($item);
            $subtotal += $pricing['total_price'];
        }

        return $subtotal;
    }

    /**
     * Coupon discount applies to items subtotal only; tax and delivery are not discounted.
     *
     * @return array{
     *     subtotal: float,
     *     discount_amount: float,
     *     subtotal_after_discount: float,
     *     tax_percentage: float,
     *     tax_amount: float,
     *     delivery_fee: float,
     *     final_amount: float
     * }
     */
    public static function calculatePricingTotals(
        float $itemsSubtotal,
        float $discountAmount,
        float $deliveryFee,
        ?float $taxPercentage = null
    ): array {
        $taxPercentage ??= self::getTaxRate();
        $discountAmount = min(max(0, $discountAmount), max(0, $itemsSubtotal));
        $itemsAfterDiscount = $itemsSubtotal - $discountAmount;
        $taxAmount = round($itemsSubtotal * $taxPercentage / 100, 2);
        $finalAmount = round($itemsAfterDiscount + $taxAmount + $deliveryFee, 2);

        return [
            'subtotal' => round($itemsSubtotal, 2),
            'discount_amount' => round($discountAmount, 2),
            'subtotal_after_discount' => round($itemsAfterDiscount, 2),
            'tax_percentage' => (float) $taxPercentage,
            'tax_amount' => $taxAmount,
            'delivery_fee' => round($deliveryFee, 2),
            'final_amount' => $finalAmount,
        ];
    }

    /**
     * Tax is calculated on the full items subtotal (coupon does not reduce the tax base).
     */
    public function getEffectiveTax(?float $subtotal = null): float
    {
        $subtotal = $subtotal ?? $this->getEffectiveSubtotal();
        $taxRate = self::getTaxRate();

        return round($subtotal * $taxRate / 100, 2);
    }

    /**
     * Get the effective final amount (subtotal - discount + tax + delivery).
     */
    public function getEffectiveFinalAmount(?float $subtotal = null, ?float $tax = null): float
    {
        $subtotal = $subtotal ?? $this->getEffectiveSubtotal();
        $discount = (float) $this->discount_amount;
        $tax = $tax ?? $this->getEffectiveTax($subtotal);
        $deliveryFee = (float) $this->delivery_fee;

        return round($subtotal - $discount + $tax + $deliveryFee, 2);
    }

    /**
     * Get all effective pricing in a single call (avoids duplicate calculations).
     *
     * @return array{subtotal: float, discount: float, tax: float, delivery_fee: float, final_total: float}
     */
    public function getEffectivePricing(): array
    {
        $subtotal = $this->getEffectiveSubtotal();
        $discount = (float) $this->discount_amount;
        $tax = $this->getEffectiveTax($subtotal);
        $finalTotal = $this->getEffectiveFinalAmount($subtotal, $tax);

        return [
            'subtotal' => $subtotal,
            'discount' => $discount,
            'tax' => $tax,
            'delivery_fee' => (float) $this->delivery_fee,
            'final_total' => $finalTotal,
        ];
    }

    /**
     * Get the delivery fee portion for a specific driver (استلام vs تسليم).
     * - Pickup only (branch_pickup): full fee to pickup driver.
     * - Delivery only (branch_dropoff): full fee to delivery driver.
     * - Both (home_pickup + home_delivery): split 50/50; if same driver does both, full fee.
     */
    public function getDeliveryFeeForDriver(int $driverId): float
    {
        $totalFee = (float) $this->delivery_fee;
        if ($totalFee <= 0) {
            return 0.0;
        }

        $pickupAtVendor = (bool) $this->pickup_at_vendor;
        $deliveryAtVendor = (bool) $this->delivery_at_vendor;
        $isPickupDriver = (int) $this->pickup_driver_id === (int) $driverId;
        $isDeliveryDriver = (int) $this->delivery_driver_id === (int) $driverId;

        if (! $isPickupDriver && ! $isDeliveryDriver) {
            return 0.0;
        }

        // No drivers (both at vendor)
        if ($pickupAtVendor && $deliveryAtVendor) {
            return 0.0;
        }

        // Pickup only: one driver does pickup
        if ($pickupAtVendor && ! $deliveryAtVendor) {
            return $isDeliveryDriver ? $totalFee : 0.0;
        }

        // Delivery only: one driver does delivery
        if (! $pickupAtVendor && $deliveryAtVendor) {
            return $isPickupDriver ? $totalFee : 0.0;
        }

        // Both pickup and delivery: split 50/50; if same driver, full
        if ($isPickupDriver && $isDeliveryDriver) {
            return $totalFee;
        }
        if ($isPickupDriver || $isDeliveryDriver) {
            return round($totalFee / 2, 2);
        }

        return 0.0;
    }

    /**
     * Get delivery fee for a role (pickup or delivery) when driver is not yet assigned.
     * Used for "new orders" / available orders list.
     *
     * @param  string  $role  'pickup'|'delivery'
     */
    public function getDeliveryFeeForRole(string $role): float
    {
        $totalFee = (float) $this->delivery_fee;
        if ($totalFee <= 0) {
            return 0.0;
        }
        $pickupAtVendor = (bool) $this->pickup_at_vendor;
        $deliveryAtVendor = (bool) $this->delivery_at_vendor;
        if ($pickupAtVendor && $deliveryAtVendor) {
            return 0.0;
        }
        if ($role === 'pickup') {
            return $deliveryAtVendor ? $totalFee : round($totalFee / 2, 2);
        }
        if ($role === 'delivery') {
            return $pickupAtVendor ? $totalFee : round($totalFee / 2, 2);
        }

        return 0.0;
    }
}
