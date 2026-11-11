<?php

declare(strict_types=1);

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Faker\Factory;
use Faker\Generator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Deterministic demo customers: the same Faker seed always yields the same rows (emails and
 * every random choice), only the dates shift with "now". Distributions are tuned for the
 * sample brief and for varied segment briefs.
 */
class CustomerSeeder extends Seeder
{
    private const CUSTOMER_COUNT = 5000;

    private const INSERT_CHUNK_SIZE = 1000;

    private const FAKER_SEED = 20260101;

    private const FAKER_LOCALE = 'tr_TR';

    /** "Last 30 days" in the sample brief. Recent events stay strictly inside it, old ones strictly outside. */
    private const RECENT_WINDOW_DAYS = 30;

    private const RECENT_MAX_DAYS_AGO = 27;

    private const OLD_MIN_DAYS_AGO = 31;

    private const SECONDS_PER_DAY = 86400;

    private const REGISTERED_DAYS_AGO_MIN = 60;

    private const REGISTERED_DAYS_AGO_MAX = 1100;

    private const NEVER_PURCHASED_SHARE = 0.15;

    private const RECENT_PURCHASE_SHARE_OF_BUYERS = 0.25;

    /**
     * Probability of a recent cart add. Without a recent purchase it is tuned so that
     * recent-cart AND no-recent-purchase lands near 12% of all customers:
     * 0.12 / (0.15 + 0.85 * 0.75).
     */
    private const CART_RECENT_SHARE_WITHOUT_RECENT_PURCHASE = 0.1524;

    private const CART_RECENT_SHARE_WITH_RECENT_PURCHASE = 0.30;

    private const OLD_CART_SHARE = 0.20;

    private const SMS_OPT_IN_SHARE = 0.70;

    private const EMAIL_OPT_IN_SHARE = 0.80;

    private const MISSING_FIRST_NAME_SHARE = 0.03;

    private const ENGLISH_SHARE = 0.10;

    /** Pyramid overall: bronze 55, silver 28, gold 13, platinum 4 (never-purchased are all bronze). */
    private const BUYER_TIER_WEIGHTS = ['bronze' => 47, 'silver' => 33, 'gold' => 15, 'platinum' => 5];

    private const TIER_ORDERS_RANGE = [
        'bronze' => [1, 5],
        'silver' => [4, 12],
        'gold' => [10, 25],
        'platinum' => [20, 50],
    ];

    private const CITY_WEIGHTS = [
        'İstanbul' => 30, 'Ankara' => 10, 'İzmir' => 8, 'Bursa' => 6, 'Antalya' => 6,
        'Adana' => 5, 'Konya' => 4, 'Gaziantep' => 4, 'Kayseri' => 3, 'Mersin' => 3,
        'Eskişehir' => 3, 'Diyarbakır' => 3, 'Samsun' => 3, 'Kocaeli' => 8, 'Muğla' => 4,
    ];

    private const PLATFORM_WEIGHTS = ['ios' => 35, 'android' => 45, 'web' => 20];

    private const CATEGORY_WEIGHTS = [
        'elektronik' => 22, 'moda' => 25, 'ev' => 14, 'kozmetik' => 12,
        'spor' => 8, 'kitap' => 6, 'market' => 13,
    ];

    private const AVG_ORDER_CENTS_RANGE = [8000, 120000];

    private const AGE_YEARS_RANGE = [18, 70];

    /** Spreads birthdays over the year so ages do not all turn over on the same day. */
    private const BIRTHDAY_EXTRA_DAYS_MAX = 364;

    private const FEMALE_SHARE = 0.5;

    /** Probabilities are compared on a 0..9999 scale, so 0.0001 is the smallest step. */
    private const CHANCE_RESOLUTION = 10000;

    private const CART_ITEMS_RANGE = [1, 6];

    private const CART_UNIT_PRICE_CENTS_RANGE = [5000, 80000];

    private Generator $faker;

    private CarbonImmutable $now;

    public function run(): void
    {
        $this->faker = Factory::create(self::FAKER_LOCALE);
        $this->faker->seed(self::FAKER_SEED);
        $this->now = CarbonImmutable::now();

        for ($start = 1; $start <= self::CUSTOMER_COUNT; $start += self::INSERT_CHUNK_SIZE) {
            $end = min($start + self::INSERT_CHUNK_SIZE - 1, self::CUSTOMER_COUNT);
            $rows = [];
            for ($number = $start; $number <= $end; $number++) {
                $rows[] = $this->customerRow($number);
            }
            DB::table('customers')->insert($rows);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function customerRow(int $number): array
    {
        $registeredDaysAgo = $this->between(self::REGISTERED_DAYS_AGO_MIN, self::REGISTERED_DAYS_AGO_MAX);
        $purchase = $this->chance(1 - self::NEVER_PURCHASED_SHARE)
            ? $this->purchaseHistory($registeredDaysAgo)
            : null;
        $cart = $this->cartActivity($registeredDaysAgo, $purchase);

        $loginDaysAgoMax = min($registeredDaysAgo - 1, $purchase['last_days_ago'] ?? PHP_INT_MAX, $cart['days_ago'] ?? PHP_INT_MAX);

        // The name follows the gender, so a "female" customer is never greeted with a man's name in previews.
        $gender = $this->chance(self::FEMALE_SHARE) ? 'female' : 'male';

        return [
            'first_name' => $this->chance(self::MISSING_FIRST_NAME_SHARE)
                ? null
                : ($gender === 'female' ? $this->faker->firstNameFemale() : $this->faker->firstNameMale()),
            'last_name' => $this->faker->lastName(),
            'email' => sprintf('musteri%d@example.com', $number),
            'phone' => sprintf('+90500%07d', $number), // reserved-looking range, never routable
            'city' => $this->weighted(self::CITY_WEIGHTS),
            'gender' => $gender,
            'birth_date' => $this->now->subYears($this->between(...self::AGE_YEARS_RANGE))
                ->subDays($this->between(0, self::BIRTHDAY_EXTRA_DAYS_MAX))->toDateString(),
            'language' => $this->chance(self::ENGLISH_SHARE) ? 'en' : 'tr',
            'registered_at' => $this->timestamp($registeredDaysAgo),
            'last_login_at' => $this->timestamp($this->between(0, $loginDaysAgoMax)),
            'platform' => $this->weighted(self::PLATFORM_WEIGHTS),
            'loyalty_tier' => $purchase['tier'] ?? 'bronze',
            'orders_count' => $purchase['orders'] ?? 0,
            'total_spent' => $this->money($purchase['total_cents'] ?? 0),
            'avg_order_value' => $this->money($purchase['average_cents'] ?? 0),
            'first_purchase_at' => $purchase['first_at'] ?? null,
            'last_purchase_at' => $purchase['last_at'] ?? null,
            'favorite_category' => $purchase['category'] ?? null,
            'last_cart_add_at' => $cart['at'],
            'cart_items_count' => $cart['items'],
            'cart_value' => $this->money($cart['value_cents']),
            'sms_opt_in' => $this->chance(self::SMS_OPT_IN_SHARE),
            'email_opt_in' => $this->chance(self::EMAIL_OPT_IN_SHARE),
        ];
    }

    /**
     * @return array{tier: string, orders: int, total_cents: int, average_cents: int, first_at: string, last_at: string, last_days_ago: int, category: string}
     */
    private function purchaseHistory(int $registeredDaysAgo): array
    {
        $tier = $this->weighted(self::BUYER_TIER_WEIGHTS);
        $orders = $this->between(...self::TIER_ORDERS_RANGE[$tier]);
        // total = average * orders in whole cents, so avg_order_value = total_spent / orders_count holds exactly.
        $averageCents = $this->between(...self::AVG_ORDER_CENTS_RANGE);

        $lastDaysAgo = $this->chance(self::RECENT_PURCHASE_SHARE_OF_BUYERS)
            ? $this->between(0, self::RECENT_MAX_DAYS_AGO)
            : $this->between(self::OLD_MIN_DAYS_AGO, $registeredDaysAgo - 2);
        $lastAt = $this->timestamp($lastDaysAgo);
        // A single order has identical first and last dates; otherwise the first one is at least a day earlier.
        $firstAt = $orders === 1
            ? $lastAt
            : $this->timestamp($this->between($lastDaysAgo + 1, $registeredDaysAgo - 1));

        return [
            'tier' => $tier,
            'orders' => $orders,
            'total_cents' => $averageCents * $orders,
            'average_cents' => $averageCents,
            'first_at' => $firstAt,
            'last_at' => $lastAt,
            'last_days_ago' => $lastDaysAgo,
            'category' => $this->weighted(self::CATEGORY_WEIGHTS),
        ];
    }

    /**
     * @param  array{last_days_ago: int}|null  $purchase
     * @return array{at: string|null, days_ago: int|null, items: int, value_cents: int}
     */
    private function cartActivity(int $registeredDaysAgo, ?array $purchase): array
    {
        $recentPurchase = $purchase !== null && $purchase['last_days_ago'] <= self::RECENT_MAX_DAYS_AGO;
        $recentCartShare = $recentPurchase
            ? self::CART_RECENT_SHARE_WITH_RECENT_PURCHASE
            : self::CART_RECENT_SHARE_WITHOUT_RECENT_PURCHASE;

        if (! $this->chance($recentCartShare)) {
            if (! $this->chance(self::OLD_CART_SHARE)) {
                return ['at' => null, 'days_ago' => null, 'items' => 0, 'value_cents' => 0];
            }
            $daysAgo = $this->between(self::OLD_MIN_DAYS_AGO, $registeredDaysAgo - 1);

            return ['at' => $this->timestamp($daysAgo), 'days_ago' => $daysAgo, 'items' => 0, 'value_cents' => 0];
        }

        // Added before the recent purchase means the cart was emptied by it; otherwise it is still open.
        if ($recentPurchase) {
            $daysAgo = $this->between($purchase['last_days_ago'] + 1, self::RECENT_WINDOW_DAYS - 1);

            return ['at' => $this->timestamp($daysAgo), 'days_ago' => $daysAgo, 'items' => 0, 'value_cents' => 0];
        }

        $daysAgo = $this->between(0, self::RECENT_WINDOW_DAYS - 1);
        $items = $this->between(...self::CART_ITEMS_RANGE);

        return [
            'at' => $this->timestamp($daysAgo),
            'days_ago' => $daysAgo,
            'items' => $items,
            'value_cents' => $items * $this->between(...self::CART_UNIT_PRICE_CENTS_RANGE),
        ];
    }

    private function between(int $min, int $max): int
    {
        return $this->faker->numberBetween($min, $max);
    }

    private function chance(float $probability): bool
    {
        return $this->between(0, self::CHANCE_RESOLUTION - 1) < (int) round($probability * self::CHANCE_RESOLUTION);
    }

    /**
     * @param  array<string, int>  $weights
     */
    private function weighted(array $weights): string
    {
        $pick = $this->between(1, array_sum($weights));
        foreach ($weights as $value => $weight) {
            $pick -= $weight;
            if ($pick <= 0) {
                return (string) $value;
            }
        }

        throw new \LogicException('Weighted pick ran past the end of the weights: '.json_encode($weights));
    }

    private function timestamp(int $daysAgo): string
    {
        return $this->now->subDays($daysAgo)->subSeconds($this->between(0, self::SECONDS_PER_DAY - 1))
            ->format('Y-m-d H:i:sP');
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
