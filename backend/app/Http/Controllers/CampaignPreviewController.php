<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Content\PreviewCampaignContent;
use App\Http\Requests\PreviewCampaignRequest;
use App\Models\Campaign;
use Illuminate\Http\JsonResponse;

class CampaignPreviewController extends Controller
{
    public function __invoke(Campaign $campaign, PreviewCampaignRequest $request, PreviewCampaignContent $preview): JsonResponse
    {
        return response()->json(['data' => $preview($campaign, $request->customer())]);
    }
}
