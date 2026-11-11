<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 16); // sms | email
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampTz('claimed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->string('idempotency_key', 64)->unique(); // sha256 hex, sent to the provider
            $table->string('provider_message_id')->nullable();
            $table->timestampTz('sent_at')->nullable();

            // The snapshot is a raw INSERT ... SELECT that bypasses Eloquent, so the database fills these.
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            // First layer against double sends: a recipient gets at most one row per campaign and channel.
            $table->unique(['campaign_id', 'customer_id', 'channel']);
            // Progress counts and the dispatcher read by campaign, channel and status.
            $table->index(['campaign_id', 'channel', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deliveries');
    }
};
