<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Delivery\ApproveCampaign;
use App\Http\Resources\CampaignResource;
use App\Models\Campaign;

class CampaignApprovalController extends Controller
{
    public function __invoke(Campaign $campaign, ApproveCampaign $approveCampaign): CampaignResource
    {
        return new CampaignResource($approveCampaign($campaign)->load('messages'));
    }
}
