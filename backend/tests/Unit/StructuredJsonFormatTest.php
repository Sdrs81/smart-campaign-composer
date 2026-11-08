<?php

declare(strict_types=1);

use App\Logging\StructuredJsonFormat;
use Illuminate\Log\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Logger as MonologLogger;

/** @return array{0: Logger, 1: resource} a logger that writes the formatted lines into memory */
function jsonLogger(): array
{
    $stream = fopen('php://memory', 'w+');
    assert($stream !== false);

    $logger = new Logger(new MonologLogger('test', [new StreamHandler($stream)]));
    (new StructuredJsonFormat)($logger);

    return [$logger, $stream];
}

/** @param  resource  $stream */
function writtenLines($stream): string
{
    rewind($stream);

    return (string) stream_get_contents($stream);
}

it('writes one JSON object per line with the message and the context', function (): void {
    [$logger, $stream] = jsonLogger();

    $logger->info('delivery.sent', ['campaign_id' => 7, 'delivery_id' => 42, 'attempt' => 2]);
    $logger->warning('llm.call.failed', ['llm_call_id' => 9]);

    $lines = explode("\n", trim(writtenLines($stream)));
    $first = json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);

    expect($lines)->toHaveCount(2)
        ->and($first['message'])->toBe('delivery.sent')
        ->and($first['level_name'])->toBe('INFO')
        ->and($first['context'])->toBe(['campaign_id' => 7, 'delivery_id' => 42, 'attempt' => 2])
        ->and(json_decode($lines[1], true, flags: JSON_THROW_ON_ERROR)['context']['llm_call_id'])->toBe(9);
});

it('masks API keys, phone numbers and e-mail addresses wherever they appear', function (string $secret): void {
    [$logger, $stream] = jsonLogger();

    $logger->warning("Provider answered 400: '{$secret}' is not a valid recipient.", ['reason' => "bad value {$secret}"]);
    $logger->error('failure', ['exception' => new RuntimeException("insert failed for {$secret}")]);

    $output = writtenLines($stream);
    expect($output)->not->toContain($secret)->toContain('[REDACTED]');
})->with([
    // A made-up key, written in two parts so the commit guard does not mistake the fixture for a real one.
    'Anthropic key' => 'sk-'.'ant-api03-AbCdEf_123-xyz',
    'E.164 phone' => '+905001234567',
    'national phone' => '05001234567',
    'e-mail' => 'ayse.yilmaz@example.com',
]);

it('masks the value of a sensitive context key whatever it looks like', function (): void {
    [$logger, $stream] = jsonLogger();

    $logger->info('call', ['api_key' => 'plain-value', 'authorization' => 'Bearer abc', 'to' => 'somebody']);

    expect(writtenLines($stream))->not->toContain('plain-value')->not->toContain('Bearer abc')->not->toContain('somebody');
});

it('leaves ids, counts and timestamps untouched', function (): void {
    [$logger, $stream] = jsonLogger();

    $logger->info('delivery.sent', ['delivery_id' => 1730000000, 'latency_ms' => 1234567890, 'at' => '2026-11-08T10:25:25+00:00']);

    expect(writtenLines($stream))->toContain('1730000000')->toContain('1234567890')->toContain('2026-11-08T10:25:25+00:00')->not->toContain('[REDACTED]');
});
