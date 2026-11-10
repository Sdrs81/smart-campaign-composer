<?php

declare(strict_types=1);

use App\Llm\FakeLlmClient;
use App\Llm\LlmClient;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;

it('runs on the fake LLM client whatever the container environment holds', function () {
    expect(config('llm.driver'))->toBe('fake')
        ->and(config('llm.anthropic.api_key'))->toBeEmpty()
        ->and(app(LlmClient::class))->toBeInstanceOf(FakeLlmClient::class);
});

it('refuses an HTTP request that no test faked', function () {
    Http::get('https://api.anthropic.com/v1/messages');
})->throws(StrayRequestException::class);
