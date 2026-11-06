<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\CampaignStatus;
use Symfony\Component\HttpFoundation\Response;

class InvalidCampaignTransition extends ApiException
{
    public static function between(CampaignStatus $from, CampaignStatus $to): self
    {
        $allowed = array_map(fn (CampaignStatus $status): string => $status->value, $from->allowedNext());

        return new self(
            sprintf(
                "Campaign cannot move from '%s' to '%s'; allowed next states: %s.",
                $from->value,
                $to->value,
                $allowed === [] ? 'none (final state)' : implode(', ', $allowed),
            ),
            ['from' => $from->value, 'to' => $to->value, 'allowed' => $allowed],
        );
    }

    public function errorCode(): string
    {
        return 'invalid_campaign_transition';
    }

    public function status(): int
    {
        return Response::HTTP_CONFLICT;
    }
}
