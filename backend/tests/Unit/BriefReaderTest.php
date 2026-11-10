<?php

declare(strict_types=1);

use App\Llm\Fake\BriefReader;
use App\Segment\FieldRegistry;
use App\Segment\SegmentRule;
use App\Segment\SegmentRuleParser;

function briefRegistry(): FieldRegistry
{
    return new FieldRegistry(fn (): array => ['Ankara', 'İstanbul', 'İzmir', 'Muğla']);
}

function condition(string $field, string $operator, mixed $value): array
{
    return ['field' => $field, 'operator' => $operator, 'value' => $value];
}

it('reads the filters a brief states', function (string $brief, array $expected): void {
    $read = (new BriefReader(briefRegistry()))->read($brief);

    expect($read)->toEqualCanonicalizing($expected);

    // Whatever the reader finds must be something the real parser accepts (K7).
    if ($read !== []) {
        expect((new SegmentRuleParser(briefRegistry()))->parse(['version' => 1, 'conditions' => $read]))->toBeInstanceOf(SegmentRule::class);
    }
})->with([
    'the case brief: city, gender, age and a week without orders' => [
        '1 haftadır sipariş vermemiş İstanbul\'daki 25 yaş altı erkekler için kampanya',
        [condition('city', 'eq', 'İstanbul'), condition('gender', 'eq', 'male'), condition('age', 'lt', 25), condition('last_purchase_at', 'not_within_last_days', 7)],
    ],
    'city with a suffix, capital letters' => ['İZMİR\'DEKİ müşterilere duyuru', [condition('city', 'eq', 'İzmir')]],
    'dotless Muğla' => ['Muğla müşterileri', [condition('city', 'eq', 'Muğla')]],
    'two cities' => ['Ankara ve İzmir\'deki müşteriler', [condition('city', 'in', ['Ankara', 'İzmir'])]],
    'woman' => ['kadın müşterilere özel', [condition('gender', 'eq', 'female')]],
    'bayan' => ['bayan müşteriler', [condition('gender', 'eq', 'female')]],
    'age and over, inclusive' => ['40 yaş ve üzeri müşteriler', [condition('age', 'gte', 40)]],
    'age and under, inclusive' => ['30 yaş ve altı', [condition('age', 'lte', 30)]],
    'age above' => ['50 yaş üstü', [condition('age', 'gt', 50)]],
    'age range with a dash' => ['25-35 yaş arası', [condition('age', 'between', [25, 35])]],
    'age range with "ile"' => ['25 ile 35 yaş arasındaki', [condition('age', 'between', [25, 35])]],
    'months become days' => ['2 aydır giriş yapmayan', [condition('last_login_at', 'not_within_last_days', 60)]],
    'a written-out number' => ['iki haftadır sipariş vermeyen', [condition('last_purchase_at', 'not_within_last_days', 14)]],
    'positive action' => ['son 7 günde giriş yapan', [condition('last_login_at', 'within_last_days', 7)]],
    'duration is capped at the parser limit' => ['5 yıl değil 40 aydır alışveriş yapmayan', [condition('last_purchase_at', 'not_within_last_days', 365)]],
    'cart and purchase in one phrase' => [
        'Son 30 gün içinde sepetine ürün ekleyip alışveriş yapmayan müşteriler',
        [condition('last_cart_add_at', 'within_last_days', 30), condition('last_purchase_at', 'not_within_last_days', 30)],
    ],
    'one tier' => ['platinum üyelerimiz', [condition('loyalty_tier', 'eq', 'platinum')]],
    'two tiers, Turkish names' => ['altın ve gümüş seviye üyeler', [condition('loyalty_tier', 'in', ['silver', 'gold'])]],
    'category and a synonym' => ['teknoloji ve moda sevenler', [condition('favorite_category', 'in', ['elektronik', 'moda'])]],
    'platform' => ['android kullanıcıları', [condition('platform', 'eq', 'android')]],
]);

it('leaves a filter out when the phrase is not clear', function (string $brief): void {
    expect((new BriefReader(briefRegistry()))->read($brief))->toBe([]);
})->with([
    '"ev" inside another word is not a category' => ['evde vakit geçirenlere indirim'],
    'both genders mean everyone' => ['erkek ve kadın müşterilere duyuru'],
    'an age without a number' => ['genç yaş grubuna kampanya'],
    '"yaş altı" read as a tier word' => ['yaş altındaki çocuklar için'],
    'a duration without an action' => ['30 gün boyunca geçerli indirim'],
    'a month-like word is not a unit' => ['3 ayakkabı çifti alana hediye'],
    'an impossible age' => ['300 yaş üstü'],
    'iPhone is not a platform filter' => ['iPhone kullananlar'],
    'a city that is not in the list' => ['Trabzon\'daki müşteriler'],
    'empty' => [''],
]);

it('describes the conditions in the chips\' wording', function (): void {
    $reader = new BriefReader(briefRegistry());

    expect($reader->describe([condition('city', 'eq', 'İstanbul'), condition('age', 'between', [25, 35]), condition('last_purchase_at', 'not_within_last_days', 7)]))
        ->toBe('Briefte belirtilen koşullara göre müşteriler seçildi: Şehir: İstanbul; Yaş: 25–35; Son alışveriş tarihi: son 7 gündür yok.');
});
