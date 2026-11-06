<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 16); // sms | email
            $table->string('subject')->nullable(); // email only
            $table->text('body');
            $table->string('source', 16); // llm | human
            $table->timestampsTz();

            // One message per channel: regenerating or editing replaces it, never adds a second.
            $table->unique(['campaign_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_messages');
    }
};
