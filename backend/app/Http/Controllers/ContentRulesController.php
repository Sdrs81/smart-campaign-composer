<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Content\Variables;
use Illuminate\Http\JsonResponse;

/** The one place the frontend learns the limits and variables, so it keeps no copy of them. */
class ContentRulesController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => [
            'channels' => config('delivery.channels'),
            'limits' => config('delivery.limits'),
            'variables' => array_map(
                fn (string $name): array => ['name' => $name, 'fallback' => Variables::fallback($name)],
                Variables::names(),
            ),
        ]]);
    }
}
