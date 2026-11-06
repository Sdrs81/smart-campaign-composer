<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table): void {
            $table->id();
            $table->text('brief');
            $table->string('status', 32)->default('draft')->index();
            $table->jsonb('segment_rule')->nullable();
            $table->jsonb('unsupported_criteria')->nullable();
            $table->jsonb('audience_counts')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};
