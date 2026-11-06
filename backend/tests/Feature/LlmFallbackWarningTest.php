<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Log;

it('logs a warning when auto falls back to the fake client', function () {
    config(['llm.requested' => 'auto', 'llm.driver' => 'fake']);
    Log::shouldReceive('warning')->once()->with(Mockery::on(fn (string $message) => str_contains($message, 'ANTHROPIC_API_KEY')));

    (new AppServiceProvider(app()))->boot();
});

it('stays silent when the fake client was chosen explicitly', function () {
    config(['llm.requested' => 'fake', 'llm.driver' => 'fake']);
    Log::shouldReceive('warning')->never();

    (new AppServiceProvider(app()))->boot();
});
