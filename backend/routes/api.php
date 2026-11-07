<?php

declare(strict_types=1);

use App\Http\Controllers\CampaignController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

// JSON API routes (prefix /api). Endpoints are added per plan task.
Route::get('/health', HealthController::class);

Route::get('/campaigns', [CampaignController::class, 'index']);
Route::post('/campaigns', [CampaignController::class, 'store']);
// whereNumber: a non-numeric id would otherwise reach Postgres and fail as a query error, not a 404.
Route::get('/campaigns/{campaign}', [CampaignController::class, 'show'])->whereNumber('campaign');
