<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response;

/** The campaign cannot be approved right now; the code says whether the audience or the timing is the reason. */
final class CampaignApprovalRefused extends ApiException
{
    /** @param  array<string, mixed>  $details */
    private function __construct(string $message, private readonly string $codeValue, private readonly int $httpStatus, array $details = [])
    {
        parent::__construct($message, $details);
    }

    public static function emptyAudience(int $campaignId): self
    {
        return new self(
            "Campaign {$campaignId} has no one to send to: no customer in the audience can receive an SMS or an email. Change the brief and define the audience again.",
            'audience_empty',
            Response::HTTP_UNPROCESSABLE_ENTITY,
            ['campaign_id' => $campaignId],
        );
    }

    public static function inProgress(int $campaignId): self
    {
        return new self(
            "Campaign {$campaignId} is being approved by another request; try again in a moment.",
            'approval_in_progress',
            Response::HTTP_CONFLICT,
            ['campaign_id' => $campaignId],
        );
    }

    public function errorCode(): string
    {
        return $this->codeValue;
    }

    public function status(): int
    {
        return $this->httpStatus;
    }
}
