<?php

declare(strict_types=1);

// Same plain autoloader as public/index.php; the service has no Composer autoload.
spl_autoload_register(static function (string $class): void {
    $prefix = 'MockProvider\\';
    if (str_starts_with($class, $prefix) && ! str_starts_with($class, $prefix.'Tests\\')) {
        require __DIR__.'/../src/'.substr($class, strlen($prefix)).'.php';
    }
});

// Not tests/Pest.php: Pest looks for that next to its own binary (the backend's), not here.
require __DIR__.'/Helpers.php';
