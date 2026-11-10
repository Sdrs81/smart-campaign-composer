<?php

declare(strict_types=1);

use App\Content\ContentValidator;
use App\Content\MessageContent;
use App\Content\PersonalizationRenderer;
use App\Enums\Channel;
use App\Llm\ContentEvent;
use App\Llm\ContentGenerator;
use App\Llm\Fake\BriefReader;
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
    'no keyword' => ['Yaz duyurusu hazırla', 'generic'],
    'discount' => ['Yaz indirimi duyurusu hazırla', 'discount'],
    'new member' => ['Yeni üye olanlara hoş geldin mesajı', 'new-member'],
    'a tier word does not hide "yeni üye"' => ['Yeni üyelerimize teşekkür et', 'new-member'],
    'big spenders' => ['VIP müşterilerimize teşekkür et', 'vip-spender'],
    'win back' => ['1 haftadır sipariş vermemiş müşteriler için kampanya', 'win-back'],
    'empty' => ['', 'generic'],
]);

it('has a fixture for every promised scenario and a generic one', function (): void {
    $names = array_column(devFixtures(), 'name');
    sort($names);

    expect($names)->toBe(['cart-abandon', 'category', 'discount', 'generic', 'inactive-login', 'loyalty-tier', 'new-member', 'vip-spender', 'win-back']);
});

it('spreads ten different briefs over at least six scenarios, not mostly the generic one', function (): void {
    $scenarios = new DevScenarios;
    $briefs = [
        'Sepeti terk edenlere hatırlatma', 'Elektronik kategorisini sevenlere duyuru', 'Yaz indirimi duyurusu hazırla',
        'Uzun süredir giriş yapmayanlara mesaj', 'Gold üyelerimize teşekkür et', 'Yeni üye olanlara hoş geldin',
        'VIP müşterilerimize özel teklif', 'Alışveriş yapmayan müşterileri geri kazan', 'Kupon kodu gönderelim', 'Bir şey duyuralım',
    ];

    $names = array_map(fn (string $brief): string => $scenarios->scenarioNameFor($brief), $briefs);

    expect(array_unique($names))->toHaveCount(9)
        ->and(array_count_values($names)['generic'])->toBe(1);
});

it('ships segment rules the real parser accepts', function (): void {
    $parser = new SegmentRuleParser(devRegistry());

    foreach (devFixtures() as $fixture) {
        expect($parser->parse($fixture['segment']['rule']))->toBeInstanceOf(SegmentRule::class, "fixture {$fixture['name']}")
            ->and($fixture['segment']['unsupported_criteria'])->toBeArray()
            ->and($fixture['segment']['explanation'])->not->toBe('');
    }
});

// One brief per scenario, so every fixture is reached through the keyword that picks it.
const DEV_SCENARIO_BRIEFS = [
    'cart-abandon' => 'sepeti terk edenler',
    'category' => 'elektronik kategorisi',
    'discount' => 'indirim duyurusu',
    'generic' => 'duyuru',
    'inactive-login' => 'giriş yapmayanlar',
    'loyalty-tier' => 'sadakat üyeleri',
    'new-member' => 'yeni üye olanlar',
    'vip-spender' => 'vip müşteriler',
    'win-back' => 'sipariş vermemiş müşteriler',
];

it('has a variant list with the shared offer slot in both channels, for every scenario', function (): void {
    foreach (devFixtures() as $fixture) {
        expect(count($fixture['content']))->toBeGreaterThanOrEqual(3, $fixture['name']);

        foreach ($fixture['content'] as $variant) {
            expect($variant['sms']['body'])->toContain('%OFFER%')
                ->and($variant['email']['body'])->toContain('%OFFER%')
                ->and($variant['offer'])->not->toBe('');
        }
    }
});

it('puts the same discount, city and category in the SMS and the email of every variant', function (): void {
    $registry = new FieldRegistry(fn (): array => ['Kahramanmaraş', 'İstanbul']);
    $scenarios = new DevScenarios(new BriefReader($registry));

    foreach (DEV_SCENARIO_BRIEFS as $name => $keywords) {
        $brief = "Kahramanmaraş'taki müşterilere %20, moda ve kozmetik ürünleri, {$keywords}";
        expect($scenarios->scenarioNameFor($brief))->toBe($name);

        foreach (range(0, $scenarios->variantCount($brief) - 1) as $variant) {
            $content = $scenarios->contentVariant($brief, $variant);
            foreach (['Kahramanmaraş', '%20'] as $fact) {
                expect($content['sms']['body'])->toContain($fact)
                    ->and($content['email']['body'])->toContain($fact);
            }
            expect($content['sms']['body'].$content['email']['body'])->not->toContain('%OFFER%')->not->toContain('%CATEGORY%');
        }
    }

    $category = $scenarios->contentVariant('elektronik ve kozmetik kategorisi için %20 indirim', 1);
    expect($category['sms']['body'])->toContain('elektronik ve kozmetik')
        ->and($category['email']['body'])->toContain('elektronik ve kozmetik');
});

it('writes no fixed city name and no other city than the brief names', function (): void {
    $cities = ['Ankara', 'İstanbul', 'İzmir', 'Bursa', 'Antalya'];
    $scenarios = new DevScenarios(new BriefReader(new FieldRegistry(fn (): array => $cities)));

    foreach (DEV_SCENARIO_BRIEFS as $name => $keywords) {
        $brief = "İstanbul'daki müşteriler için {$keywords}";
        foreach (range(0, $scenarios->variantCount($brief) - 1) as $variant) {
            $content = $scenarios->contentVariant($brief, $variant);
            $text = $content['sms']['body'].$content['email']['subject'].$content['email']['body'];

            expect($text)->toContain('İstanbul')
                ->and($text)->not->toContain('Ankara')->not->toContain('İzmir')->not->toContain('Bursa')->not->toContain('Antalya');
        }
    }
});

it('ships messages that fit the limits even for very long customer values and the longest facts in the brief', function (): void {
    $validator = new ContentValidator(new PersonalizationRenderer, 160, 80, 2000);
    $longest = ['first_name' => 30, 'last_name' => 30, 'city' => 30];
    $scenarios = new DevScenarios(new BriefReader(new FieldRegistry(fn (): array => ['Kahramanmaraş'])));

    foreach (DEV_SCENARIO_BRIEFS as $name => $keywords) {
        // The longest city, a two-digit discount and two categories at once.
        foreach (["Kahramanmaraş'taki %99 moda kozmetik {$keywords}", $keywords] as $brief) {
            foreach (range(0, $scenarios->variantCount($brief) - 1) as $variant) {
                $content = $scenarios->contentVariant($brief, $variant);
                $sms = new MessageContent(Channel::Sms, null, $content['sms']['body']);
                $email = new MessageContent(Channel::Email, $content['email']['subject'], $content['email']['body']);

                expect($validator->validate($sms, $longest)?->messages())->toBeNull("sms of {$name} #{$variant}")
                    ->and($validator->validate($email, $longest)?->messages())->toBeNull("email of {$name} #{$variant}");
            }
        }
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

it('lays the filters the brief states over the scenario rule', function (): void {
    $scenarios = new DevScenarios(new BriefReader(devRegistry()));
    $segment = fn (string $brief): array => json_decode($scenarios->respond(devRequest('segment', $brief))->text, true, flags: JSON_THROW_ON_ERROR);

    // The generic scenario's "everyone" condition goes away once the brief names real filters.
    $caseBrief = $segment('1 haftadır sipariş vermemiş İstanbul\'daki 25 yaş altı erkekler için kampanya');
    expect($caseBrief['rule']['conditions'])->toEqualCanonicalizing([
        ['field' => 'city', 'operator' => 'eq', 'value' => 'İstanbul'],
        ['field' => 'gender', 'operator' => 'eq', 'value' => 'male'],
        ['field' => 'age', 'operator' => 'lt', 'value' => 25],
        ['field' => 'last_purchase_at', 'operator' => 'not_within_last_days', 'value' => 7],
    ])->and($caseBrief['explanation'])->toContain('Şehir: İstanbul');

    // The brief wins on a field the scenario also sets (here 90 days becomes 30), the rest of the scenario stays.
    $inactive = $segment('30 gündür giriş yapmayan Ankara müşterileri');
    expect($inactive['rule']['conditions'])->toBe([
        ['field' => 'last_login_at', 'operator' => 'not_within_last_days', 'value' => 30],
        ['field' => 'city', 'operator' => 'eq', 'value' => 'Ankara'],
    ]);

    // The worked example keeps its old rule exactly, and a brief with no filters keeps the scenario's answer.
    $withoutReader = json_decode((new DevScenarios)->respond(devRequest('segment', DEV_CART_BRIEF))->text, true, flags: JSON_THROW_ON_ERROR);
    expect($segment(DEV_CART_BRIEF)['rule'])->toBe($withoutReader['rule'])
        ->and($segment('Yaz duyurusu hazırla')['rule']['conditions'][0]['field'])->toBe('registered_at');
});

/** A run counter like the cache one in the app, but in memory. */
function devRunCounter(): Closure
{
    $runs = [];

    return function (string $key) use (&$runs): int {
        $runs[$key] = ($runs[$key] ?? 0) + 1;

        return $runs[$key];
    };
}

it('steps through the variants on every request for the same brief and wraps around', function (): void {
    $scenarios = new DevScenarios(new BriefReader(devRegistry()), devRunCounter());
    $brief = "İstanbul'daki müşterilere sepet hatırlatması";
    $variants = array_map(fn (int $number): array => (new DevScenarios(new BriefReader(devRegistry())))->contentVariant($brief, $number), [0, 1, 2]);
    $ask = fn (): array => json_decode($scenarios->respond(devRequest('content', $brief))->text, true, flags: JSON_THROW_ON_ERROR);

    $answers = [$ask(), $ask(), $ask(), $ask()];

    expect($answers[0])->toBe($variants[0])
        ->and($answers[1])->toBe($variants[1])
        ->and($answers[2])->toBe($variants[2])
        ->and($answers[3])->toBe($variants[0])
        ->and($answers[0]['sms']['body'])->not->toBe($answers[1]['sms']['body'])
        ->and($answers[1]['sms']['body'])->not->toBe($answers[2]['sms']['body']);
});

it('keeps the audience and the facts the same while the text changes', function (): void {
    $registry = new FieldRegistry(fn (): array => ['Ankara', 'İstanbul', 'İzmir', 'Bursa']);
    $scenarios = new DevScenarios(new BriefReader($registry), devRunCounter());
    $brief = "İstanbul'daki 25 yaş altı erkeklere %20 indirim kampanyası";
    $rule = fn (): array => json_decode($scenarios->respond(devRequest('segment', $brief))->text, true, flags: JSON_THROW_ON_ERROR)['rule'];
    $firstRule = $rule();

    foreach (range(1, 6) as $run) {
        $content = json_decode($scenarios->respond(devRequest('content', $brief))->text, true, flags: JSON_THROW_ON_ERROR);
        $text = $content['sms']['body'].$content['email']['subject'].$content['email']['body'];

        expect($text)->toContain('İstanbul')->toContain('%20')
            ->and($text)->not->toContain('Ankara')->not->toContain('İzmir')->not->toContain('Bursa')
            ->and($rule())->toBe($firstRule);
    }
});

it('counts runs per brief, so a different brief starts again from the first variant', function (): void {
    $scenarios = new DevScenarios(new BriefReader(devRegistry()), devRunCounter());
    $text = fn (string $brief): string => $scenarios->respond(devRequest('content', $brief))->text;

    $text('Sepeti terk edenler');
    $second = $text('Sepeti terk edenler');
    $otherFirst = $text('Sepeti terk eden başka müşteriler');

    expect($otherFirst)->not->toBe($second)
        ->and($otherFirst)->toBe((new DevScenarios(new BriefReader(devRegistry())))->respond(devRequest('content', 'Sepeti terk eden başka müşteriler'))->text);
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
