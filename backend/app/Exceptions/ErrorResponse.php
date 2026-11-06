<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * The single place that builds the API error body.
 */
final class ErrorResponse
{
    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, string>  $headers
     */
    public static function make(string $code, string $message, int $status, array $details = [], array $headers = []): JsonResponse
    {
        return response()->json(
            ['error' => ['code' => $code, 'message' => $message, 'details' => (object) $details]],
            $status,
            $headers,
        );
    }
}
