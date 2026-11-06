<?php

declare(strict_types=1);

namespace App\Enums;

enum DeliveryStatus: string
{
    case Pending = 'pending';
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Skipped = 'skipped';

    /**
     * The single source of truth for delivery transitions. Pending to Sending is the atomic
     * claim (a conditional UPDATE, K19); Sending back to Pending is a scheduled retry.
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending => [self::Sending, self::Skipped],
            self::Sending => [self::Pending, self::Sent, self::Failed, self::Skipped],
            self::Sent, self::Failed, self::Skipped => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    /** A terminal delivery is never touched again, so a repeated job exits early (idempotent). */
    public function isTerminal(): bool
    {
        return $this->allowedNext() === [];
    }
}
