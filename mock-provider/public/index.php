<?php

declare(strict_types=1);

use MockProvider\Application;

// No Composer: the service is a handful of classes, so a plain autoloader is enough.
spl_autoload_register(static function (string $class): void {
    $prefix = 'MockProvider\\';
    if (str_starts_with($class, $prefix)) {
        require __DIR__.'/../src/'.substr($class, strlen($prefix)).'.php';
    }
});

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

Application::fromEnvironment()->handle(
    $_SERVER['REQUEST_METHOD'] ?? 'GET',
    is_string($path) ? $path : '/',
    (string) file_get_contents('php://input'),
    $_SERVER,
)->emit();
