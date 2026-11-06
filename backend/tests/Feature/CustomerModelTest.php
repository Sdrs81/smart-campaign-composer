<?php

declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('persists a factory customer and casts its columns', function () {
    $customer = Customer::factory()->create(['sms_opt_in' => false])->fresh();

    expect($customer->sms_opt_in)->toBeFalse()
        ->and($customer->email)->toEndWith('@example.com')
        ->and($customer->total_spent)->toBe('0.00');
});

it('derives age from birth_date', function () {
    $customer = Customer::factory()->make(['birth_date' => now()->subYears(30)->subDay()]);

    expect($customer->age())->toBe(30);
});
