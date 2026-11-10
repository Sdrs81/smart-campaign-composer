<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table): void {
            // One of App\Delivery\DeliveryErrorCode (K24); null for rows written before the column existed.
            $table->string('last_error_code', 32)->nullable()->after('last_error');
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->dropColumn('last_error_code');
        });
    }
};
