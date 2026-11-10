<?php

declare(strict_types=1);

// Loads config/llm.php directly: no framework boot, env is set per case.
function llmConfig(string $driver, string $apiKey): array
{
    $names = ['LLM_DRIVER', 'ANTHROPIC_API_KEY'];
    // $_ENV and $_SERVER are kept apart: phpunit.xml pins these names in $_SERVER only (K28), and the
    // Feature tests that run later need that value back.
    $previousEnv = array_intersect_key($_ENV, array_flip($names));
    $previousServer = array_intersect_key($_SERVER, array_flip($names));

    foreach (array_combine($names, [$driver, $apiKey]) as $name => $value) {
        $_ENV[$name] = $_SERVER[$name] = $value;
    }

    try {
        return require dirname(__DIR__, 2).'/config/llm.php';
    } finally {
        foreach ($names as $name) {
            unset($_ENV[$name], $_SERVER[$name]);
        }
        $_ENV = [...$_ENV, ...$previousEnv];
        $_SERVER = [...$_SERVER, ...$previousServer];
    }
}

it('resolves auto to fake when the api key is empty', function () {
    expect(llmConfig('auto', '')['driver'])->toBe('fake');
});

it('resolves auto to claude when an api key is present', function () {
    expect(llmConfig('auto', 'test-key')['driver'])->toBe('claude');
});

it('keeps an explicit driver regardless of the key', function (string $driver, string $key) {
    expect(llmConfig($driver, $key)['driver'])->toBe($driver);
})->with([
    'fake with key' => ['fake', 'test-key'],
    'claude without key' => ['claude', ''],
]);

it('defaults the model to claude-sonnet-5-5', function () {
    expect(llmConfig('auto', '')['anthropic']['model'])->toBe('claude-sonnet-5-5');
});
