<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CampaignMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CampaignMessage
 */
class CampaignMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'channel' => $this->channel,
            'subject' => $this->subject,
            'body' => $this->body,
            'source' => $this->source,
        ];
    }
}
