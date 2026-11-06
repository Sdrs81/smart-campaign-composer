<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('llm_calls', function (Blueprint $table): void {
            $table->id();
            $table->string('purpose', 32); // segment | content ...
            $table->string('prompt_version', 16)->nullable();
            $table->string('model', 64);
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('latency_ms');
            $table->unsignedSmallInteger('attempt'); // 1 = first try, 2+ = repair rounds
            $table->string('outcome', 16); // succeeded | failed | invalid | aborted
            $table->text('error_message')->nullable();
            $table->jsonb('validation_errors')->nullable();
            // Raw model output only: briefs and templates are never stored, so no personal data lands here.
            $table->text('raw_output')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['purpose', 'outcome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_calls');
    }
};
