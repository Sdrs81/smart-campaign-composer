<?php

declare(strict_types=1);

use App\Llm\ContentGenerator;
use App\Llm\FakeLlmClient;
use App\Llm\LlmClient;
use App\Llm\SegmentExtractor;
use App\Models\LlmCall;
use App\Segment\AudienceSummary;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

it('keeps the tests strict: with the phpunit setting the container fake answers nothing it was not given', function (): void {
    $fake = app(LlmClient::class);
    assert($fake instanceof FakeLlmClient);

    expect(config('llm.fake.dev_scenarios'))->toBeFalse();
    expect(fn () => app(SegmentExtractor::class)->extract('Sepeti terk eden müşteriler', CarbonImmutable::now()))
        ->toThrow(LogicException::class, 'no scenario left');
});

it('answers from the ready-made scenarios when the setting is on, as it is for the running app', function (): void {
    config(['llm.fake.dev_scenarios' => true]);
    Sleep::fake();

    $extraction = app(SegmentExtractor::class)->extract('Son 30 günde sepete ürün ekleyip alışveriş yapmayan müşteriler', CarbonImmutable::now());
    $events = iterator_to_array(app(ContentGenerator::class)->generate(
        'Sepeti terk edenlere hatırlatma',
        'samimi',
        new AudienceSummary(['sms' => 5, 'email' => 5], 5, [], ['sms' => ['first_name' => 10], 'email' => ['first_name' => 10]]),
        $extraction->explanation,
    ), false);

    expect($extraction->rule->conditions)->toHaveCount(2)
        ->and(end($events)->type)->toBe('final')
        ->and(LlmCall::query()->where('model', 'fake')->count())->toBe(2);
});
