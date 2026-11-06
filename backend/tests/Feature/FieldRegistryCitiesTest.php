<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Segment\FieldRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

it('reads the city list from the database and caches it', function (): void {
    Cache::flush();
    Customer::factory()->create(['city' => 'Ankara']);
    Customer::factory()->create(['city' => 'Ankara']);
    Customer::factory()->create(['city' => 'İzmir']);

    $cities = app(FieldRegistry::class)->find('city')->allowedValues();

    expect($cities)->toBe(['Ankara', 'İzmir'])
        ->and(Cache::get('segment.cities'))->toBe(['Ankara', 'İzmir']);
});

it('does not cache an empty city list', function (): void {
    Cache::flush();

    expect(app(FieldRegistry::class)->find('city')->allowedValues())->toBe([])
        ->and(Cache::has('segment.cities'))->toBeFalse();

    Customer::factory()->create(['city' => 'Bursa']);

    expect(app(FieldRegistry::class)->find('city')->allowedValues())->toBe(['Bursa']);
});
