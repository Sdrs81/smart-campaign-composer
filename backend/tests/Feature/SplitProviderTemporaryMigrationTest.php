<?php

declare(strict_types=1);

use App\Delivery\DeliveryErrorCode;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Delivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('turns a stored provider_temporary into provider_error so the row still loads', function (): void {
    $delivery = Delivery::create([
        'campaign_id' => Campaign::create(['brief' => 'Sepet terk'])->id,
        'customer_id' => Customer::factory()->create()->id,
        'channel' => 'sms',
        'idempotency_key' => hash('sha256', 'old-code'),
    ]);
    DB::table('deliveries')->where('id', $delivery->id)->update(['last_error_code' => 'provider_temporary']);

    (require database_path('migrations/2026_11_10_000002_split_provider_temporary_error_code.php'))->up();

    expect($delivery->fresh()->last_error_code)->toBe(DeliveryErrorCode::ProviderError);
});
