<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\DefineAudienceRequest;
use App\Http\Resources\CampaignResource;
use App\Models\Campaign;
use App\Segment\DefineCampaignAudience;

class CampaignAudienceController extends Controller
{
    public function __invoke(Campaign $campaign, DefineAudienceRequest $request, DefineCampaignAudience $defineAudience): CampaignResource
    {
        return new CampaignResource($defineAudience($campaign, $request->validated('brief')));
    }
}
