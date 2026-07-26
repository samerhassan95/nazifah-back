<?php

namespace App\Enums;

enum OrderItemStatus: string
{
    case PENDING = 'pending';
    case ACCEPTED = 'accepted';
    case REJECTED = 'rejected';
    case MODIFIED = 'modified';
    case IN_PROGRESS = 'in_progress';
    case COMPLETED = 'completed';

    /**
     * Get all order item status values
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get order item status label in English
     */
    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::ACCEPTED => 'Accepted',
            self::REJECTED => 'Rejected',
            self::MODIFIED => 'Modified',
            self::IN_PROGRESS => 'In Progress',
            self::COMPLETED => 'Completed',
        };
    }

    /**
     * Get order item status label in Arabic
     */
    public function labelAr(): string
    {
        return match ($this) {
            self::PENDING => 'قيد الانتظار',
            self::ACCEPTED => 'مقبول',
            self::REJECTED => 'مرفوض',
            self::MODIFIED => 'معدل',
            self::IN_PROGRESS => 'قيد التنفيذ',
            self::COMPLETED => 'مكتمل',
        };
    }

    /**
     * Get localized label based on current locale
     */
    public function localizedLabel(): string
    {
        return app()->getLocale() === 'ar' ? $this->labelAr() : $this->label();
    }

    /**
     * Get status color for UI
     */
    public function color(): string
    {
        return match ($this) {
            self::PENDING => '#FFA500',      // Orange
            self::ACCEPTED => '#28A745',     // Green
            self::REJECTED => '#DC3545',     // Red
            self::MODIFIED => '#FFC107',     // Amber
            self::IN_PROGRESS => '#17A2B8',  // Cyan
            self::COMPLETED => '#28A745',    // Green
        };
    }

    /**
     * Get status icon for UI
     */
    public function icon(): string
    {
        return match ($this) {
            self::PENDING => '⏳',
            self::ACCEPTED => '✅',
            self::REJECTED => '❌',
            self::MODIFIED => '✏️',
            self::IN_PROGRESS => '🔄',
            self::COMPLETED => '✔️',
        };
    }

    /**
     * Get status badge style
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::PENDING => 'badge-warning',
            self::ACCEPTED => 'badge-success',
            self::REJECTED => 'badge-danger',
            self::MODIFIED => 'badge-info',
            self::IN_PROGRESS => 'badge-primary',
            self::COMPLETED => 'badge-success',
        };
    }

    /**
     * Check if item is pending vendor review
     */
    public function isPending(): bool
    {
        return $this === self::PENDING;
    }

    /**
     * Check if item was accepted by vendor
     */
    public function isAccepted(): bool
    {
        return $this === self::ACCEPTED;
    }

    /**
     * Check if item was rejected by vendor
     */
    public function isRejected(): bool
    {
        return $this === self::REJECTED;
    }

    /**
     * Check if item was modified by vendor
     */
    public function isModified(): bool
    {
        return $this === self::MODIFIED;
    }

    /**
     * Check if item is in progress
     */
    public function isInProgress(): bool
    {
        return $this === self::IN_PROGRESS;
    }

    /**
     * Check if item is completed
     */
    public function isCompleted(): bool
    {
        return $this === self::COMPLETED;
    }

    /**
     * Check if item requires client approval
     */
    public function requiresClientApproval(): bool
    {
        return in_array($this, [
            self::REJECTED,
            self::MODIFIED,
        ]);
    }

    /**
     * Check if item can be processed
     */
    public function canBeProcessed(): bool
    {
        return in_array($this, [
            self::ACCEPTED,
            self::IN_PROGRESS,
        ]);
    }

    /**
     * Get next possible statuses
     */
    public function nextStatuses(): array
    {
        return match ($this) {
            self::PENDING => [
                self::ACCEPTED,
                self::REJECTED,
                self::MODIFIED,
            ],
            self::ACCEPTED => [
                self::IN_PROGRESS,
            ],
            self::MODIFIED => [
                self::ACCEPTED,
                self::REJECTED,
            ],
            self::IN_PROGRESS => [
                self::COMPLETED,
            ],
            self::REJECTED, self::COMPLETED => [],
        };
    }

    /**
     * Get description for status
     */
    public function description(): string
    {
        return match ($this) {
            self::PENDING => 'Item is waiting for vendor review',
            self::ACCEPTED => 'Item has been accepted by vendor',
            self::REJECTED => 'Item has been rejected by vendor',
            self::MODIFIED => 'Item quantity or price has been modified by vendor',
            self::IN_PROGRESS => 'Item is being processed',
            self::COMPLETED => 'Item processing is complete',
        };
    }

    /**
     * Get description for status in Arabic
     */
    public function descriptionAr(): string
    {
        return match ($this) {
            self::PENDING => 'القطعة في انتظار مراجعة المغسلة',
            self::ACCEPTED => 'تم قبول القطعة من قبل المغسلة',
            self::REJECTED => 'تم رفض القطعة من قبل المغسلة',
            self::MODIFIED => 'تم تعديل الكمية أو السعر من قبل المغسلة',
            self::IN_PROGRESS => 'القطعة قيد المعالجة',
            self::COMPLETED => 'تم الانتهاء من معالجة القطعة',
        };
    }

    /**
     * Get localized description based on current locale
     */
    public function localizedDescription(): string
    {
        return app()->getLocale() === 'ar' ? $this->descriptionAr() : $this->description();
    }

    /**
     * Create from string value
     */
    public static function fromString(string $value): ?self
    {
        return self::tryFrom($value);
    }

    /**
     * Get all vendor review statuses (statuses set by vendor)
     */
    public static function vendorReviewStatuses(): array
    {
        return [
            self::ACCEPTED,
            self::REJECTED,
            self::MODIFIED,
        ];
    }

    /**
     * Get all active processing statuses
     */
    public static function activeStatuses(): array
    {
        return [
            self::PENDING,
            self::ACCEPTED,
            self::MODIFIED,
            self::IN_PROGRESS,
        ];
    }

    /**
     * Get all final statuses (no further changes)
     */
    public static function finalStatuses(): array
    {
        return [
            self::REJECTED,
            self::COMPLETED,
        ];
    }
}
