<?php

declare(strict_types=1);

use App\Enums\LlmCallOutcome;
use App\Llm\Exceptions\LlmUnavailable;
use App\Llm\FakeLlmClient;
use App\Llm\LlmClient;
use App\Llm\LlmRequest;
use App\Llm\LlmResponse;
use App\Llm\LlmUsage;
use App\Llm\RecordingLlmClient;
use App\Models\LlmCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function recordedRequest(int $attempt = 1): LlmRequest
{
    return new LlmRequest('system', [['role' => 'user', 'content' => 'brief']], 256, null, 'segment', 'segment-v1', $attempt);
}

it('records one succeeded row with usage, raw output and latency', function (): void {
    $fake = (new FakeLlmClient)->enqueue(new LlmResponse('{"ok":true}', new LlmUsage(11, 4)));
    $client = new RecordingLlmClient($fake, 'claude-sonnet-5-5');

    $response = $client->complete(recordedRequest());

    $call = LlmCall::query()->sole();
    expect($response->text)->toBe('{"ok":true}')
        ->and($call->purpose)->toBe('segment')
        ->and($call->prompt_version)->toBe('segment-v1')
        ->and($call->model)->toBe('claude-sonnet-5-5')
        ->and($call->input_tokens)->toBe(11)
        ->and($call->output_tokens)->toBe(4)
        ->and($call->attempt)->toBe(1)
        ->and($call->outcome)->toBe(LlmCallOutcome::Succeeded)
        ->and($call->raw_output)->toBe('{"ok":true}')
        ->and($call->error_message)->toBeNull()
        ->and($call->latency_ms)->toBeGreaterThan(0);
});

it('logs the call with its llm_call_id but without the raw answer', function (): void {
    Log::spy();
    $client = new RecordingLlmClient((new FakeLlmClient)->enqueue(new LlmResponse('gizli cevap metni', new LlmUsage(11, 4))), 'claude-sonnet-5-5');

    $client->complete(recordedRequest());

    $call = LlmCall::query()->sole();
    Log::shouldHaveReceived('log')->once()->withArgs(fn (string $level, string $message, array $context): bool => $level === 'info'
        && $message === 'llm.call.succeeded'
        && $context['llm_call_id'] === $call->id
        && $context['purpose'] === 'segment'
        && $context['input_tokens'] === 11
        && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'gizli cevap metni'));
});

it('still returns the answer when the call log cannot be written', function (): void {
    // Rolled back with the test transaction; the table is gone so the insert fails.
    Schema::drop('llm_calls');
    $client = new RecordingLlmClient((new FakeLlmClient)->enqueue(new LlmResponse('{"ok":true}', new LlmUsage(1, 1))), 'claude-sonnet-5-5');

    expect($client->complete(recordedRequest())->text)->toBe('{"ok":true}')
        ->and($client->lastCall())->toBeNull();
});

it('records one failed row and rethrows the original exception', function (): void {
    $failure = new LlmUnavailable('Claude API unavailable after 3 attempts; last failure: status 529.');
    $client = new RecordingLlmClient((new FakeLlmClient)->enqueue($failure), 'claude-sonnet-5-5');

    expect(fn () => $client->complete(recordedRequest()))->toThrow($failure);

    $call = LlmCall::query()->sole();
    expect($call->outcome)->toBe(LlmCallOutcome::Failed)
        ->and($call->error_message)->toContain(LlmUnavailable::class)->toContain('status 529')
        ->and($call->input_tokens)->toBeNull()
        ->and($call->raw_output)->toBeNull()
        ->and($call->latency_ms)->toBeGreaterThan(0);
});

it('records a finished stream as one row with the joined text', function (): void {
    $client = new RecordingLlmClient((new FakeLlmClient)->enqueue(new LlmResponse('Merhaba dünya, kampanya hazır', new LlmUsage(8, 6))), 'fake');

    $chunks = [...$client->stream(recordedRequest())];

    $call = LlmCall::query()->sole();
    expect($chunks)->not->toBeEmpty()
        ->and($call->outcome)->toBe(LlmCallOutcome::Succeeded)
        ->and($call->raw_output)->toBe('Merhaba dünya, kampanya hazır')
        ->and($call->output_tokens)->toBe(6);
});

it('records a failing stream as failed', function (): void {
    $client = new RecordingLlmClient((new FakeLlmClient)->enqueue(new LlmUnavailable('down')), 'fake');

    expect(fn () => iterator_to_array($client->stream(recordedRequest())))->toThrow(LlmUnavailable::class);

    expect(LlmCall::query()->sole()->outcome)->toBe(LlmCallOutcome::Failed);
});

it('records a stream the caller abandons as aborted', function (): void {
    $client = new RecordingLlmClient((new FakeLlmClient)->enqueue('Uzun bir mesaj metni burada'), 'fake');

    // Leaving the loop after the first chunk drops the generator, which is what a closed browser tab does.
    foreach ($client->stream(recordedRequest()) as $chunk) {
        break;
    }

    $call = LlmCall::query()->sole();
    expect($call->outcome)->toBe(LlmCallOutcome::Aborted)
        ->and($call->raw_output)->not->toBe('Uzun bir mesaj metni burada');
});

it('keeps the repair attempt number and lets the caller mark a call invalid', function (): void {
    $client = new RecordingLlmClient((new FakeLlmClient)->enqueue('{"bad":1}'), 'fake');
    $client->complete(recordedRequest(attempt: 2));

    $call = $client->lastCall();
    assert($call !== null);
    $call->markInvalid(['conditions[0].field: unknown field "foo"']);
    $call->save();

    $stored = LlmCall::query()->sole();
    expect($stored->attempt)->toBe(2)
        ->and($stored->outcome)->toBe(LlmCallOutcome::Invalid)
        ->and($stored->validation_errors)->toBe(['conditions[0].field: unknown field "foo"']);
});

it('is resolved from the container around the shared client, so queued fake scenarios are recorded', function (): void {
    $fake = app(LlmClient::class);
    assert($fake instanceof FakeLlmClient);
    $fake->enqueue('{"ok":true}');

    $response = app(RecordingLlmClient::class)->complete(recordedRequest());

    expect($response->text)->toBe('{"ok":true}')
        ->and(app(RecordingLlmClient::class))->toBe(app(RecordingLlmClient::class))
        ->and(LlmCall::query()->sole()->model)->toBe('fake');
});
