<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Content\UpdateCampaignContent;
use App\Http\Requests\UpdateCampaignContentRequest;
use App\Http\Resources\CampaignResource;
use App\Models\Campaign;

class CampaignContentController extends Controller
{
    public function update(Campaign $campaign, UpdateCampaignContentRequest $request, UpdateCampaignContent $updateContent): CampaignResource
    {
        return new CampaignResource($updateContent($campaign, $request->sms(), $request->email()));
    }
}
