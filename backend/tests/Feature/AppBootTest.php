<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

it('boots the application in the testing environment', function () {
    expect(app()->environment())->toBe('testing');
});

it('connects to the separate test database, never the development one', function () {
    expect(DB::connection()->getDatabaseName())->toBe('campaign_test');
});
