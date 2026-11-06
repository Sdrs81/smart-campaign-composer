<?php

declare(strict_types=1);

it('defines the content limits and sending defaults in one place', function () {
    $config = require dirname(__DIR__, 2).'/config/delivery.php';

    expect($config['limits'])->toBe([
        'sms_body_max' => 160,
        'email_subject_max' => 80,
        'email_body_max' => 2000,
    ])
        ->and($config['rate_per_second'])->toBe(100)
        ->and($config['workers_per_channel'])->toBe(8)
        ->and($config['channels'])->toBe(['sms', 'email']);
});
