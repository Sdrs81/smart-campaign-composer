<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();

            // Identity and contact. first_name is nullable so the greeting fallback can be tested.
            $table->string('first_name')->nullable();
            $table->string('last_name');
            $table->string('email')->nullable()->unique();
            $table->string('phone', 16)->nullable(); // E.164

            // Demographics. Age is derived from birth_date, never stored.
            $table->string('city');
            $table->string('gender', 16);
            $table->date('birth_date');
            $table->string('language', 2); // tr | en

            // Account and engagement
            $table->timestampTz('registered_at');
            $table->timestampTz('last_login_at')->nullable();
            $table->string('platform', 16); // ios | android | web
            $table->string('loyalty_tier', 16); // bronze | silver | gold | platinum

            // Purchases
            $table->unsignedInteger('orders_count')->default(0);
            $table->decimal('total_spent', 12, 2)->default(0);
            $table->decimal('avg_order_value', 12, 2)->default(0);
            $table->timestampTz('first_purchase_at')->nullable();
            $table->timestampTz('last_purchase_at')->nullable();
            $table->string('favorite_category')->nullable();

            // Cart
            $table->timestampTz('last_cart_add_at')->nullable();
            $table->unsignedInteger('cart_items_count')->default(0);
            $table->decimal('cart_value', 12, 2)->default(0);

            // Consent
            $table->boolean('sms_opt_in')->default(false);
            $table->boolean('email_opt_in')->default(false);

            // Indexes for the columns segments filter on most (K5).
            $table->index('city');
            $table->index('loyalty_tier');
            $table->index('favorite_category');
            $table->index('birth_date');
            $table->index('registered_at');
            $table->index('last_login_at');
            $table->index('first_purchase_at');
            $table->index('last_purchase_at');
            $table->index('last_cart_add_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
