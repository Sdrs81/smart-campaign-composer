<?php

declare(strict_types=1);

namespace MockProvider;

use JsonException;
use Redis;
use RuntimeException;

/**
 * Remembers the first answer per Idempotency-Key. One SET NX decides the winner,
 * so two workers racing on the same key cannot both count a message.
 */
final class IdempotencyStore
{
    private const PREFIX = 'mock:idempotency:';

    private const RETENTION_SECONDS = 86400;

    public function __construct(private readonly Redis $redis) {}

    /**
     * Same body, same key: the stored answer. Different body, same key: a conflict.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $response  What a first request with this key gets
     */
    public function remember(Channel $channel, string $key, array $payload, array $response): IdempotencyResult
    {
        $fingerprint = $this->fingerprint($channel, $payload);
        $entry = json_encode(['fingerprint' => $fingerprint, 'response' => $response], JSON_THROW_ON_ERROR);

        if ($this->redis->set(self::PREFIX.$key, $entry, ['nx', 'ex' => self::RETENTION_SECONDS])) {
            return new IdempotencyResult(IdempotencyOutcome::Created, $response);
        }

        $stored = $this->stored($key);
        $outcome = $stored['fingerprint'] === $fingerprint ? IdempotencyOutcome::Replayed : IdempotencyOutcome::Conflict;

        return new IdempotencyResult($outcome, $stored['response']);
    }

    /**
     * Test reset. Without it a key reused after DELETE /stats would be answered as a duplicate.
     */
    public function forgetAll(): void
    {
        $keys = $this->redis->keys(self::PREFIX.'*');
        if (is_array($keys) && $keys !== []) {
            $this->redis->del($keys);
        }
    }

    /**
     * @return array{fingerprint: string, response: array<string, string>}
     */
    private function stored(string $key): array
    {
        $raw = $this->redis->get(self::PREFIX.$key);
        if (! is_string($raw)) {
            throw new RuntimeException("Idempotency key '{$key}' expired between the write attempt and the read.");
        }

        try {
            /** @var array{fingerprint: string, response: array<string, string>} */
            return json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Stored entry for idempotency key '{$key}' is not valid JSON.", 0, $exception);
        }
    }

    /**
     * Field order must not matter, and the channel is part of the request.
     *
     * @param  array<string, mixed>  $payload
     */
    private function fingerprint(Channel $channel, array $payload): string
    {
        ksort($payload);

        return hash('sha256', $channel->value.json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
