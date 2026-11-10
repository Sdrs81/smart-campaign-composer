<?php

declare(strict_types=1);

use Database\Seeders\CustomerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const SEEDED_CUSTOMERS = 5000;
const SHARE_TOLERANCE = 3.0;

function emailFingerprint(): string
{
    $row = DB::selectOne("SELECT count(*) AS total, md5(string_agg(email, ',' ORDER BY id)) AS fingerprint FROM customers");

    return $row->total.':'.$row->fingerprint;
}

/** Share of customers (in percent) matching a SQL condition. */
function shareOf(string $condition): float
{
    return (float) DB::selectOne("SELECT 100.0 * count(*) FILTER (WHERE {$condition}) / count(*) AS share FROM customers")->share;
}

it('seeds the same customers every time', function () {
    $this->seed(CustomerSeeder::class);
    $first = emailFingerprint();

    DB::statement('TRUNCATE customers RESTART IDENTITY CASCADE');
    $this->seed(CustomerSeeder::class);

    expect($first)->toStartWith(SEEDED_CUSTOMERS.':')
        ->and(emailFingerprint())->toBe($first);
});

it('keeps purchase, cart and name columns consistent', function () {
    $this->seed(CustomerSeeder::class);

    // A first name belongs to one gender only, so a preview never greets a female customer with a man's name.
    $namesUsedByBothGenders = DB::selectOne(<<<'SQL'
        SELECT count(*) AS names FROM (
            SELECT first_name FROM customers WHERE first_name IS NOT NULL
            GROUP BY first_name HAVING count(DISTINCT gender) > 1
        ) AS shared
    SQL)->names;
    expect($namesUsedByBothGenders)->toBe(0);

    $violations = DB::selectOne(<<<'SQL'
        SELECT count(*) FILTER (WHERE first_purchase_at > last_purchase_at) AS dates_swapped,
               count(*) FILTER (WHERE orders_count = 0 AND (first_purchase_at IS NOT NULL OR last_purchase_at IS NOT NULL OR total_spent <> 0)) AS phantom_purchase,
               count(*) FILTER (WHERE orders_count > 0 AND (first_purchase_at IS NULL OR last_purchase_at IS NULL)) AS missing_dates,
               count(*) FILTER (WHERE orders_count > 0 AND avg_order_value <> round(total_spent / orders_count, 2)) AS wrong_average,
               count(*) FILTER (WHERE first_purchase_at < registered_at) AS bought_before_registering,
               count(*) FILTER (WHERE cart_items_count > 0 AND last_cart_add_at IS NULL) AS cart_without_date
        FROM customers
    SQL);

    expect((array) $violations)->each->toBe(0);
});

it('spreads customers over the segments the briefs target', function () {
    $this->seed(CustomerSeeder::class);

    $targets = [
        'city = \'İstanbul\'' => 30,
        'orders_count = 0' => 15,
        'last_cart_add_at >= now() - interval \'30 days\' AND (last_purchase_at IS NULL OR last_purchase_at < now() - interval \'30 days\')' => 12,
        'sms_opt_in' => 70,
        'email_opt_in' => 80,
        'first_name IS NULL' => 3,
        'language = \'en\'' => 10,
        'loyalty_tier = \'bronze\'' => 55,
        'loyalty_tier = \'silver\'' => 28,
        'loyalty_tier = \'gold\'' => 13,
        'loyalty_tier = \'platinum\'' => 4,
    ];

    foreach ($targets as $condition => $expected) {
        expect(shareOf($condition))->toEqualWithDelta($expected, SHARE_TOLERANCE, $condition);
    }
    expect(DB::selectOne('SELECT count(DISTINCT city) AS cities FROM customers')->cities)->toBe(15);
});
