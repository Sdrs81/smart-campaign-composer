<?php

declare(strict_types=1);

// Loads config/llm.php directly: no framework boot, env is set per case.
function llmConfig(string $driver, string $apiKey): array
{
    $previous = [$_ENV['LLM_DRIVER'] ?? null, $_ENV['ANTHROPIC_API_KEY'] ?? null];

    foreach (['LLM_DRIVER' => $driver, 'ANTHROPIC_API_KEY' => $apiKey] as $name => $value) {
        $_ENV[$name] = $_SERVER[$name] = $value;
    }

    try {
        return require dirname(__DIR__, 2).'/config/llm.php';
    } finally {
        foreach (['LLM_DRIVER' => $previous[0], 'ANTHROPIC_API_KEY' => $previous[1]] as $name => $value) {
            if ($value === null) {
                unset($_ENV[$name], $_SERVER[$name]);
            } else {
                $_ENV[$name] = $_SERVER[$name] = $value;
            }
        }
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
