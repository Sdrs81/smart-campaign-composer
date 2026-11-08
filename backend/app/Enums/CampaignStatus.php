<?php

declare(strict_types=1);

namespace App\Enums;

enum CampaignStatus: string
{
    case Draft = 'draft';
    case AudienceReady = 'audience_ready';
    case ContentReady = 'content_ready';
    case Approved = 'approved';
    case Sending = 'sending';
    case Completed = 'completed';
    case Failed = 'failed';

    /**
     * The single source of truth for the state machine. Audience and content can be redone
     * until approval, because the rule is read-only and the brief is what gets changed, and
     * approving twice is allowed so a retried request is harmless (K15).
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::AudienceReady],
            self::AudienceReady => [self::AudienceReady, self::ContentReady],
            self::ContentReady => [self::AudienceReady, self::ContentReady, self::Approved],
            self::Approved => [self::Approved, self::Sending],
            self::Sending => [self::Completed, self::Failed],
            self::Completed, self::Failed => [],
        };
    }

    /**
     * True once a person has approved the campaign and it is still alive or done, so a repeated approval has
     * nothing left to do. A failed campaign is excluded: approving it must be refused, not answered with success.
     */
    public function isApprovedOrLater(): bool
    {
        return in_array($this, [self::Approved, self::Sending, self::Completed], true);
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }
}
