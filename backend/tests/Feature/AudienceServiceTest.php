<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Segment\AudienceService;
use App\Segment\FieldRegistry;
use App\Segment\SegmentQueryCompiler;
use App\Segment\SegmentRule;
use App\Segment\SegmentRuleParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function audienceRule(string $city): SegmentRule
{
    $parser = new SegmentRuleParser(new FieldRegistry(fn (): array => ['Ankara', 'İstanbul']));
    $rule = $parser->parse(['version' => 1, 'conditions' => [['field' => 'city', 'operator' => 'eq', 'value' => $city]]]);
    expect($rule)->toBeInstanceOf(SegmentRule::class);

    return $rule;
}

function audience(): AudienceService
{
    return new AudienceService(new SegmentQueryCompiler);
}

it('counts only customers who may receive each channel and counts a customer reachable twice once', function (): void {
    Customer::factory()->create(['city' => 'İstanbul']);
    Customer::factory()->create(['city' => 'İstanbul', 'sms_opt_in' => false]);
    Customer::factory()->create(['city' => 'İstanbul', 'email_opt_in' => false]);
    Customer::factory()->create(['city' => 'İstanbul', 'sms_opt_in' => false, 'email_opt_in' => false]);
    Customer::factory()->create(['city' => 'İstanbul', 'phone' => null]);
    Customer::factory()->create(['city' => 'İstanbul', 'email' => null, 'phone' => '']);
    Customer::factory()->create(['city' => 'Ankara']);

    $summary = audience()->summarize(audienceRule('İstanbul'));

    // sms: customers 1 and 3; email: 1, 2 and 5; reachable at all: 1, 2, 3 and 5.
    expect($summary->channelCounts)->toBe(['sms' => 2, 'email' => 3])
        ->and($summary->uniqueTotal)->toBe(4);
});

it('runs exactly two queries', function (): void {
    Customer::factory()->count(8)->create(['city' => 'İstanbul']);

    DB::enableQueryLog();
    audience()->summarize(audienceRule('İstanbul'));
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toHaveCount(2);
});

it('samples five reachable customers in a stable order', function (): void {
    $unreachable = Customer::factory()->create(['city' => 'İstanbul', 'sms_opt_in' => false, 'email_opt_in' => false]);
    $reachable = Customer::factory()->count(7)->create(['city' => 'İstanbul']);

    $sample = audience()->summarize(audienceRule('İstanbul'))->sample;

    expect($sample)->toHaveCount(5)
        ->and(array_column($sample, 'id'))->toBe($reachable->take(5)->pluck('id')->all())
        ->and(array_column($sample, 'id'))->not->toContain($unreachable->id)
        ->and(array_keys($sample[0]))->toBe(['id', 'first_name', 'last_name', 'city']);
});

it('reports the longest personalisation value per channel from consenting customers only', function (): void {
    Customer::factory()->create(['city' => 'İstanbul', 'first_name' => 'Ali', 'last_name' => 'Yılmaz', 'sms_opt_in' => false]);
    Customer::factory()->create(['city' => 'İstanbul', 'first_name' => 'Şükrücan', 'last_name' => 'Öz', 'email_opt_in' => false]);
    Customer::factory()->create(['city' => 'İstanbul', 'first_name' => null, 'last_name' => 'Karaca']);

    $longest = audience()->summarize(audienceRule('İstanbul'))->longestValues;

    // Şükrücan is 8 characters and only reachable by SMS; Yılmaz has no SMS consent so it counts for email only.
    expect($longest['sms'])->toBe(['first_name' => 8, 'last_name' => 6, 'city' => 8])
        ->and($longest['email'])->toBe(['first_name' => 3, 'last_name' => 6, 'city' => 8]);
});

it('returns zeros and an empty sample for an audience nobody belongs to', function (): void {
    Customer::factory()->create(['city' => 'Ankara']);

    $summary = audience()->summarize(audienceRule('İstanbul'));

    expect($summary->channelCounts)->toBe(['sms' => 0, 'email' => 0])
        ->and($summary->uniqueTotal)->toBe(0)
        ->and($summary->sample)->toBe([])
        ->and($summary->longestValues['sms'])->toBe(['first_name' => 0, 'last_name' => 0, 'city' => 0]);
});
