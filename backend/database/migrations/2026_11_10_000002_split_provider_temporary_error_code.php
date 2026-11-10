<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // The first code set had one "provider_temporary" for every transient failure. It is gone from
        // DeliveryErrorCode, so a stored one would no longer load; the cause is lost, "provider_error" is closest.
        DB::table('deliveries')
            ->where('last_error_code', 'provider_temporary')
            ->update(['last_error_code' => 'provider_error']);
    }

    public function down(): void
    {
        // The split cannot be undone: the original cause of each row is not known.
    }
};
