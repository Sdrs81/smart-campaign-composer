<?php

declare(strict_types=1);

use App\Content\ContentValidator;
use App\Content\MessageContent;
use App\Content\PersonalizationRenderer;
use App\Enums\Channel;
use App\Llm\ContentEvent;
use App\Llm\ContentGenerator;
use App\Llm\Fake\DevScenarios;
use App\Llm\FakeLlmClient;
use App\Llm\LlmRequest;
use App\Llm\Prompts\ContentPrompt;
use App\Llm\Prompts\SegmentPrompt;
use App\Llm\SegmentExtractor;
use App\Segment\AudienceSummary;
use App\Segment\FieldRegistry;
use App\Segment\SegmentRule;
use App\Segment\SegmentRuleParser;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval as Duration;
use Illuminate\Support\Sleep;

const DEV_CART_BRIEF = 'Son 30 günde sepete ürün ekleyip alışveriş yapmayan müşteriler';

function devRegistry(): FieldRegistry
{
    return new FieldRegistry(fn (): array => ['Ankara', 'İstanbul']);
}

function devRequest(string $purpose, string $brief): LlmRequest
{
    return new LlmRequest('system', [['role' => 'user', 'content' => "<brief>{$brief}</brief>"]], 1024, null, $purpose);
}

/** @return list<array<string, mixed>> every fixture file, decoded */
function devFixtures(): array
{
    return array_map(
        fn (string $file): array => json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR),
        glob(__DIR__.'/../../resources/llm-fixtures/*.json') ?: [],
    );
}

it('picks the scenario by keyword, also for capital Turkish letters', function (string $brief, string $expected): void {
    expect((new DevScenarios)->scenarioNameFor($brief))->toBe($expected);
})->with([
    'cart abandon' => [DEV_CART_BRIEF, 'cart-abandon'],
    'capital dotted İ' => ['SEPETİ TERK EDENLER', 'cart-abandon'],
    'loyalty tier' => ['Gold ve platinum üyelerimize teşekkür et', 'loyalty-tier'],
    'category' => ['Elektronik kategorisini sevenlere duyuru', 'category'],
    'long time no login' => ['Uzun süredir giriş yapmayan kullanıcılar', 'inactive-login'],
    'capital GİRİŞ' => ['GİRİŞ YAPMAYANLARA hatırlatma', 'inactive-login'],
    'dotless ı and ş together' => ['Sadakat programındaki müşteriler', 'loyalty-tier'],
    'no keyword' => ['Yaz indirimi duyurusu hazırla', 'generic'],
    'empty' => ['', 'generic'],
]);

it('has a fixture for every promised scenario and a generic one', function (): void {
    $names = array_column(devFixtures(), 'name');
    sort($names);

    expect($names)->toBe(['cart-abandon', 'category', 'generic', 'inactive-login', 'loyalty-tier']);
});

it('ships segment rules the real parser accepts', function (): void {
    $parser = new SegmentRuleParser(devRegistry());

    foreach (devFixtures() as $fixture) {
        expect($parser->parse($fixture['segment']['rule']))->toBeInstanceOf(SegmentRule::class, "fixture {$fixture['name']}")
            ->and($fixture['segment']['unsupported_criteria'])->toBeArray()
            ->and($fixture['segment']['explanation'])->not->toBe('');
    }
});

it('ships messages that fit the limits even for very long customer values', function (): void {
    $validator = new ContentValidator(new PersonalizationRenderer, 160, 80, 2000);
    $longest = ['first_name' => 30, 'last_name' => 30, 'city' => 30];

    foreach (devFixtures() as $fixture) {
        $content = $fixture['content'];
        $sms = new MessageContent(Channel::Sms, null, $content['sms']['body']);
        $email = new MessageContent(Channel::Email, $content['email']['subject'], $content['email']['body']);

        expect($validator->validate($sms, $longest)?->messages())->toBeNull("sms of {$fixture['name']}")
            ->and($validator->validate($email, $longest)?->messages())->toBeNull("email of {$fixture['name']}");
    }
});

it('answers a segment request with the scenario rule as JSON', function (): void {
    $response = (new DevScenarios)->respond(devRequest('segment', DEV_CART_BRIEF));
    $answer = json_decode($response->text, true, flags: JSON_THROW_ON_ERROR);

    expect(array_keys($answer))->toBe(['rule', 'unsupported_criteria', 'explanation'])
        ->and(array_column($answer['rule']['conditions'], 'field'))->toBe(['last_cart_add_at', 'last_purchase_at'])
        ->and($response->usage->inputTokens)->toBeGreaterThan(0)
        ->and($response->usage->outputTokens)->toBeGreaterThan(0);
});

it('answers a content request with sms and email text', function (): void {
    $answer = json_decode((new DevScenarios)->respond(devRequest('content', DEV_CART_BRIEF))->text, true, flags: JSON_THROW_ON_ERROR);

    expect(array_keys($answer))->toBe(['sms', 'email'])
        ->and($answer['sms']['body'])->toContain('{{first_name}}');
});

it('reads the brief from the first message only, so a repair round keeps the scenario', function (): void {
    $request = new LlmRequest('system', [
        ['role' => 'user', 'content' => '<brief>'.DEV_CART_BRIEF.'</brief>'],
        ['role' => 'assistant', 'content' => '{}'],
        ['role' => 'user', 'content' => 'giriş yapmayan <brief>hata mesajı</brief>'],
    ], 1024, null, 'segment');

    expect(json_decode((new DevScenarios)->respond($request)->text, true)['rule']['conditions'][0]['field'])->toBe('last_cart_add_at');
});

it('refuses a purpose it has no answer for, naming the supported ones', function (): void {
    expect(fn () => (new DevScenarios)->respond(devRequest('general', 'brief')))
        ->toThrow(LogicException::class, "no answer for purpose 'general'; supported: segment, content");
});

it('lets queued scenarios go first and falls back to the dev answers after them', function (): void {
    $fake = (new FakeLlmClient(new DevScenarios))->enqueue('kuyruktaki cevap');

    expect($fake->complete(devRequest('segment', DEV_CART_BRIEF))->text)->toBe('kuyruktaki cevap')
        ->and($fake->complete(devRequest('segment', DEV_CART_BRIEF))->text)->toContain('last_cart_add_at');
});

it('still fails on an empty queue when no dev scenarios are given', function (): void {
    expect(fn () => (new FakeLlmClient)->complete(devRequest('segment', DEV_CART_BRIEF)))
        ->toThrow(LogicException::class, 'no scenario left');
});

it('pauses 20 to 40 ms before every streamed piece of a dev answer', function (): void {
    Sleep::fake();
    $fake = new FakeLlmClient(new DevScenarios);

    $chunks = [...$fake->stream(devRequest('content', DEV_CART_BRIEF))];

    expect(count($chunks))->toBeGreaterThan(10);
    Sleep::assertSleptTimes(count($chunks));
    Sleep::assertSlept(fn (Duration $pause): bool => $pause->totalMilliseconds >= 20 && $pause->totalMilliseconds <= 40, times: count($chunks));
});

it('does not pause when streaming a queued test scenario', function (): void {
    Sleep::fake();

    [...(new FakeLlmClient(new DevScenarios))->enqueue('Merhaba dünya, kampanya hazır')->stream(devRequest('content', 'brief'))];

    Sleep::assertNeverSlept();
});

it('drives the real extractor and generator end to end with no scenario queued', function (): void {
    Sleep::fake();
    $registry = devRegistry();
    $fake = new FakeLlmClient(new DevScenarios);
    $extractor = new SegmentExtractor($fake, new SegmentPrompt($registry), new SegmentRuleParser($registry), 2, 4096, 10, 1000);
    $generator = new ContentGenerator($fake, new ContentPrompt(160, 80, 2000), new ContentValidator(new PersonalizationRenderer, 160, 80, 2000), 2, 8192);

    $extraction = $extractor->extract(DEV_CART_BRIEF, CarbonImmutable::parse('2026-11-07'));
    $audience = new AudienceSummary(['sms' => 10, 'email' => 10], 10, [], ['sms' => ['first_name' => 12], 'email' => ['first_name' => 12]]);
    $events = iterator_to_array($generator->generate(DEV_CART_BRIEF, 'samimi', $audience, $extraction->explanation), false);
    $last = end($events);

    expect($extraction->attempts)->toBe(1)
        ->and($extraction->rule->conditions)->toHaveCount(2)
        ->and($last->type)->toBe(ContentEvent::COMPLETED)
        ->and($last->repaired)->toBeFalse()
        ->and($last->sms->body)->toContain('sepet');
});
