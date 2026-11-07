<?php

declare(strict_types=1);

use App\Enums\LlmCallOutcome;
use App\Llm\ContentGenerator;
use App\Llm\FakeLlmClient;
use App\Llm\LlmClient;
use App\Models\LlmCall;
use App\Segment\AudienceSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function loggedAudience(): AudienceSummary
{
    return new AudienceSummary(['sms' => 10, 'email' => 10], 10, [], ['sms' => ['first_name' => 8], 'email' => ['first_name' => 8]]);
}

function loggedFake(): FakeLlmClient
{
    $fake = app(LlmClient::class);
    assert($fake instanceof FakeLlmClient);

    return $fake;
}

it('logs the streamed call as invalid and the repair call as succeeded', function (): void {
    $valid = json_encode(['sms' => ['body' => 'Merhaba'], 'email' => ['subject' => 'Konu', 'body' => 'Gövde']], JSON_THROW_ON_ERROR);
    loggedFake()->enqueue(json_encode(['sms' => ['body' => str_repeat('a', 161)], 'email' => ['subject' => 'Konu', 'body' => 'Gövde']]), $valid);

    $events = iterator_to_array(app(ContentGenerator::class)->generate('Teşekkür kampanyası', 'samimi', loggedAudience(), 'Açıklama'), false);

    $calls = LlmCall::query()->orderBy('id')->get();
    expect(end($events)->repaired)->toBeTrue()
        ->and($calls)->toHaveCount(2)
        ->and($calls[0]->outcome)->toBe(LlmCallOutcome::Invalid)
        ->and($calls[0]->attempt)->toBe(1)
        ->and($calls[0]->validation_errors[0])->toContain('sms.body')
        ->and($calls[1]->outcome)->toBe(LlmCallOutcome::Succeeded)
        ->and($calls[1]->attempt)->toBe(2)
        ->and($calls->pluck('purpose')->unique()->all())->toBe(['content'])
        ->and($calls->pluck('prompt_version')->unique()->all())->toBe(['content-v1']);
});

it('logs a stream the consumer abandons as aborted and makes no further call', function (): void {
    loggedFake()->enqueue(json_encode(['sms' => ['body' => 'Merhaba'], 'email' => ['subject' => 'Konu', 'body' => 'Gövde']], JSON_THROW_ON_ERROR));

    $events = app(ContentGenerator::class)->generate('Teşekkür kampanyası', 'samimi', loggedAudience(), 'Açıklama');
    $events->current();
    unset($events);

    $call = LlmCall::query()->sole();
    expect($call->outcome)->toBe(LlmCallOutcome::Aborted)
        ->and($call->purpose)->toBe('content');
});

it('reads its token and repair limits from config', function (): void {
    config(['llm.content.max_tokens' => 1234]);
    $fake = loggedFake();
    $fake->enqueue(json_encode(['sms' => ['body' => 'Merhaba'], 'email' => ['subject' => 'Konu', 'body' => 'Gövde']], JSON_THROW_ON_ERROR));

    iterator_to_array(app(ContentGenerator::class)->generate('Teşekkür kampanyası', 'samimi', loggedAudience(), 'Açıklama'), false);

    expect($fake->requests()[0]->maxTokens)->toBe(1234);
});
