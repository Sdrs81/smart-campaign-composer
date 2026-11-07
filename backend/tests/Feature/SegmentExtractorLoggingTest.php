<?php

declare(strict_types=1);

use App\Enums\LlmCallOutcome;
use App\Llm\FakeLlmClient;
use App\Llm\LlmClient;
use App\Llm\SegmentExtractor;
use App\Models\LlmCall;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('logs every model call of a repaired extraction, marking the rejected one invalid', function (): void {
    $fake = app(LlmClient::class);
    assert($fake instanceof FakeLlmClient);
    $valid = json_encode([
        'rule' => ['version' => 1, 'conditions' => [['field' => 'gender', 'operator' => 'eq', 'value' => 'female']]],
        'unsupported_criteria' => [],
        'explanation' => 'Kadın müşteriler.',
    ], JSON_THROW_ON_ERROR);
    $fake->enqueue('bu bir JSON değil', $valid);

    $extraction = app(SegmentExtractor::class)->extract('Kadın müşterilerimize özel bir kampanya', CarbonImmutable::parse('2026-11-06'));

    $calls = LlmCall::query()->orderBy('id')->get();
    expect($extraction->attempts)->toBe(2)
        ->and($calls)->toHaveCount(2)
        ->and($calls[0]->outcome)->toBe(LlmCallOutcome::Invalid)
        ->and($calls[0]->attempt)->toBe(1)
        ->and($calls[0]->validation_errors[0])->toContain('not valid JSON')
        ->and($calls[1]->outcome)->toBe(LlmCallOutcome::Succeeded)
        ->and($calls[1]->attempt)->toBe(2)
        ->and($calls->pluck('purpose')->unique()->all())->toBe(['segment'])
        ->and($calls->pluck('prompt_version')->unique()->all())->toBe(['segment-v1']);
});
