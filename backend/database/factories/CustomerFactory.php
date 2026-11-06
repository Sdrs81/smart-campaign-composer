<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(), // example.com only
            'phone' => '+90500'.fake()->unique()->numerify('#######'), // not a routable number range
            'city' => fake()->randomElement(['İstanbul', 'Ankara', 'İzmir']),
            'gender' => fake()->randomElement(['female', 'male']),
            'birth_date' => fake()->dateTimeBetween('-60 years', '-18 years')->format('Y-m-d'),
            'language' => 'tr',
            'registered_at' => fake()->dateTimeBetween('-3 years', '-1 month'),
            'last_login_at' => fake()->dateTimeBetween('-1 month', 'now'),
            'platform' => fake()->randomElement(['ios', 'android', 'web']),
            'loyalty_tier' => 'bronze',
            'orders_count' => 0,
            'total_spent' => 0,
            'avg_order_value' => 0,
            'first_purchase_at' => null,
            'last_purchase_at' => null,
            'favorite_category' => null,
            'last_cart_add_at' => null,
            'cart_items_count' => 0,
            'cart_value' => 0,
            'sms_opt_in' => true,
            'email_opt_in' => true,
        ];
    }
}
