<?php

declare(strict_types=1);

use App\Llm\ClaudeLlmClient;
use App\Llm\FakeLlmClient;
use App\Llm\LlmChunk;
use App\Llm\LlmClient;
use App\Llm\LlmRequest;
use App\Llm\LlmResponse;
use App\Llm\LlmUsage;
use App\Providers\LlmServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;

function llmRequest(): LlmRequest
{
    return new LlmRequest('system', [['role' => 'user', 'content' => 'brief']], 256);
}

// Registers the provider on a bare application: no boot, no database.
function llmClientFor(string $driver, string $apiKey = ''): LlmClient
{
    $app = new Application(dirname(__DIR__, 2));
    $app->instance('config', new Repository(['llm' => [
        'driver' => $driver,
        'anthropic' => ['api_key' => $apiKey],
    ]]));
    $app->register(LlmServiceProvider::class);

    return $app->make(LlmClient::class);
}

it('serves scenarios in queue order, failures included', function (): void {
    $fake = (new FakeLlmClient)->enqueue('not json', new RuntimeException('boom'), 'valid');

    expect($fake->complete(llmRequest())->text)->toBe('not json');
    expect(fn () => $fake->complete(llmRequest()))->toThrow(RuntimeException::class, 'boom');
    expect($fake->complete(llmRequest())->text)->toBe('valid')
        ->and($fake->requests())->toHaveCount(3);
});

it('returns a queued response unchanged', function (): void {
    $response = new LlmResponse('cut', new LlmUsage(10, 5), LlmResponse::STOP_MAX_TOKENS);

    expect((new FakeLlmClient)->enqueue($response)->complete(llmRequest()))->toBe($response);
});

it('fails clearly when the queue is empty', function (): void {
    expect(fn () => (new FakeLlmClient)->complete(llmRequest()))
        ->toThrow(LogicException::class, 'no scenario left');
});

it('streams chunks that join to the full text, with the stop reason on the last one only', function (): void {
    $text = 'Merhaba, sepetinizde ürün kaldı. İndirim sizi bekliyor!';
    $chunks = [...(new FakeLlmClient)->enqueue($text)->stream(llmRequest())];

    expect(count($chunks))->toBeGreaterThan(1)
        ->and(implode('', array_map(fn (LlmChunk $chunk): string => $chunk->text, $chunks)))->toBe($text)
        ->and(array_map(fn (LlmChunk $chunk): bool => $chunk->isLast(), $chunks))
        ->toBe([...array_fill(0, count($chunks) - 1, false), true]);
});

it('raises a queued failure when the stream is consumed', function (): void {
    $stream = (new FakeLlmClient)->enqueue(new RuntimeException('down'))->stream(llmRequest());

    expect(fn () => iterator_to_array($stream))->toThrow(RuntimeException::class, 'down');
});

it('binds the fake client for the fake driver, as a shared instance', function (): void {
    expect(llmClientFor('fake'))->toBeInstanceOf(FakeLlmClient::class);
});

it('binds the claude client for the claude driver with a key', function (): void {
    expect(llmClientFor('claude', 'test-key'))->toBeInstanceOf(ClaudeLlmClient::class);
});

it('refuses the claude driver without a key', function (): void {
    expect(fn () => llmClientFor('claude'))->toThrow(InvalidArgumentException::class, 'ANTHROPIC_API_KEY');
});

it('refuses an unknown driver', function (): void {
    expect(fn () => llmClientFor('claud', 'test-key'))->toThrow(InvalidArgumentException::class, "'claud'");
});
