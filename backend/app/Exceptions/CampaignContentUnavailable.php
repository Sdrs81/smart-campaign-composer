<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\CampaignStatus;
use Symfony\Component\HttpFoundation\Response;

/** The campaign is in a state where the requested content operation makes no sense. */
class CampaignContentUnavailable extends ApiException
{
    public static function notEditable(CampaignStatus $status): self
    {
        return new self(
            sprintf("Content can only be edited while the campaign is '%s'; it is '%s'.", CampaignStatus::ContentReady->value, $status->value),
            ['status' => $status->value, 'required' => CampaignStatus::ContentReady->value],
        );
    }

    public static function notGenerated(CampaignStatus $status): self
    {
        return new self(
            "The campaign has no generated content to preview yet; it is '{$status->value}'. Generate the content first.",
            ['status' => $status->value],
        );
    }

    public function errorCode(): string
    {
        return 'campaign_content_unavailable';
    }

    public function status(): int
    {
        return Response::HTTP_CONFLICT;
    }
}
