<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Delivery\CampaignStats;
use App\Models\Campaign;
use Illuminate\Http\JsonResponse;

class CampaignStatsController extends Controller
{
    public function __invoke(Campaign $campaign, CampaignStats $stats): JsonResponse
    {
        return response()->json(['data' => $stats($campaign)]);
    }
}
