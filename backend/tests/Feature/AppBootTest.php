<?php

declare(strict_types=1);

it('boots the application in the testing environment', function () {
    expect(app()->environment())->toBe('testing');
});
