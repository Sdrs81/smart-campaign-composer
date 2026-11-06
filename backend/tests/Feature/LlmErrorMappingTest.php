<?php

declare(strict_types=1);

use App\Llm\Exceptions\LlmBadResponse;
use App\Llm\Exceptions\LlmRefused;
use App\Llm\Exceptions\LlmTruncated;
use App\Llm\Exceptions\LlmUnavailable;
use Illuminate\Support\Facades\Route;

it('renders LLM failures in the shared error body with the right status', function (string $exception, int $status, string $code): void {
    Route::get('api/llm-failure', fn () => throw new $exception('Claude said no.'));

    $this->getJson('/api/llm-failure')
        ->assertStatus($status)
        ->assertExactJson(['error' => ['code' => $code, 'message' => 'Claude said no.', 'details' => []]]);
})->with([
    'unavailable' => [LlmUnavailable::class, 503, 'llm_unavailable'],
    'truncated' => [LlmTruncated::class, 502, 'llm_truncated'],
    'refused' => [LlmRefused::class, 502, 'llm_refused'],
    'bad response' => [LlmBadResponse::class, 502, 'llm_bad_response'],
]);
