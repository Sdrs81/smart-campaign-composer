<?php

declare(strict_types=1);

use App\Http\Controllers\CampaignApprovalController;
use App\Http\Controllers\CampaignAudienceController;
use App\Http\Controllers\CampaignContentController;
use App\Http\Controllers\CampaignContentStreamController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\CampaignPreviewController;
use App\Http\Controllers\ContentRulesController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

// JSON API routes (prefix /api). Endpoints are added per plan task.
Route::get('/health', HealthController::class);

Route::get('/campaigns', [CampaignController::class, 'index']);
Route::post('/campaigns', [CampaignController::class, 'store']);
// whereNumber: a non-numeric id would otherwise reach Postgres and fail as a query error, not a 404.
Route::get('/campaigns/{campaign}', [CampaignController::class, 'show'])->whereNumber('campaign');
Route::post('/campaigns/{campaign}/audience', CampaignAudienceController::class)->whereNumber('campaign');
Route::post('/campaigns/{campaign}/approve', CampaignApprovalController::class)->whereNumber('campaign');
Route::put('/campaigns/{campaign}/content', [CampaignContentController::class, 'update'])->whereNumber('campaign');
Route::get('/campaigns/{campaign}/preview', CampaignPreviewController::class)->whereNumber('campaign');
Route::get('/content/rules', ContentRulesController::class);
Route::post('/campaigns/{campaign}/content/stream', CampaignContentStreamController::class)->whereNumber('campaign');
