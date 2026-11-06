<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

// JSON API routes (prefix /api). Endpoints are added per plan task.
Route::get('/health', HealthController::class);
