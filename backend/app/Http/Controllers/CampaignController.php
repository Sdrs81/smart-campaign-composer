<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreCampaignRequest;
use App\Http\Resources\CampaignResource;
use App\Models\Campaign;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CampaignController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return CampaignResource::collection(Campaign::query()->latest('id')->paginate());
    }

    public function store(StoreCampaignRequest $request): CampaignResource
    {
        // A freshly created resource is rendered with 201 by Laravel.
        return new CampaignResource(Campaign::create(['brief' => $request->validated('brief')]));
    }

    public function show(Campaign $campaign): CampaignResource
    {
        return new CampaignResource($campaign->load('messages'));
    }
}
