<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Campaign;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Campaign
 */
class CampaignResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'brief' => $this->brief,
            'status' => $this->status,
            'segment_rule' => $this->segment_rule,
            'unsupported_criteria' => $this->unsupported_criteria,
            'audience_counts' => $this->audience_counts,
            // Only the detail endpoint loads messages; the list stays one query.
            'messages' => CampaignMessageResource::collection($this->whenLoaded('messages')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
