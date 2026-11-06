<?php

use Tests\TestCase;

uses(TestCase::class);

it('boots the application in the testing environment', function () {
    expect(app()->environment())->toBe('testing');
});
