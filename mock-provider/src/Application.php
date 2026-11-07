<?php

declare(strict_types=1);

namespace MockProvider;

use JsonException;
use Redis;

/**
 * Single entry point: maps method and path to a handler and always answers JSON.
 */
final class Application
{
    private const IDEMPOTENCY_HEADER = 'HTTP_IDEMPOTENCY_KEY';

    public function __construct(
        private readonly Stats $stats,
        private readonly IdempotencyStore $idempotency,
        private readonly RecipientValidator $recipients,
    ) {}

    public static function fromEnvironment(): self
    {
        $redis = new Redis;
        // getenv, not $_ENV: variables_order may omit E under php -S.
        $redis->connect((string) (getenv('REDIS_HOST') ?: 'redis'), (int) (getenv('REDIS_PORT') ?: 6379));

        return new self(new Stats($redis), new IdempotencyStore($redis), new RecipientValidator);
    }

    /**
     * @param  array<string, mixed>  $server  $_SERVER
     */
    public function handle(string $method, string $path, string $rawBody, array $server): void
    {
        $channel = Channel::tryFrom(ltrim($path, '/'));

        match (true) {
            $method === 'GET' && $path === '/health' => $this->json(200, ['status' => 'ok']),
            $method === 'GET' && $path === '/stats' => $this->json(200, $this->stats->snapshot()),
            $method === 'DELETE' && $path === '/stats' => $this->resetStats(),
            $channel !== null && $method === 'POST' => $this->send($channel, $rawBody, $server),
            $channel !== null => $this->error(405, 'method_not_allowed', "{$method} {$path} is not supported; use POST."),
            default => $this->error(404, 'not_found', "No route for {$method} {$path}."),
        };
    }

    /**
     * @param  array<string, mixed>  $server
     */
    private function send(Channel $channel, string $rawBody, array $server): void
    {
        $key = $server[self::IDEMPOTENCY_HEADER] ?? '';
        if (! is_string($key) || $key === '') {
            $this->error(400, 'idempotency_key_required', 'The Idempotency-Key header is required and must not be empty.');

            return;
        }

        try {
            $payload = json_decode($rawBody, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error(400, 'invalid_json', 'The request body must be a JSON object: '.$exception->getMessage());

            return;
        }

        $problem = $this->payloadProblem($channel, $payload);
        if ($problem !== null) {
            $this->error(400, 'invalid_payload', $problem);

            return;
        }

        if (! $this->recipients->isValid($channel, $payload['to'])) {
            $this->error(400, 'invalid_recipient', "'{$payload['to']}' is not a valid {$channel->value} recipient.");

            return;
        }

        $result = $this->idempotency->remember(
            $channel,
            $key,
            $payload,
            ['id' => bin2hex(random_bytes(16)), 'status' => 'accepted'],
        );

        if ($result->outcome === IdempotencyOutcome::Conflict) {
            $this->error(422, 'idempotency_key_reused', "The Idempotency-Key '{$key}' was already used with a different {$channel->value} request body.");

            return;
        }

        if ($result->outcome === IdempotencyOutcome::Created) {
            $this->stats->recordAccepted($channel, time());
        } else {
            $this->stats->recordDuplicate($channel);
        }

        $this->json(200, $result->response);
    }

    private function resetStats(): void
    {
        $this->stats->reset();
        $this->idempotency->forgetAll();
        $this->json(200, ['status' => 'reset']);
    }

    private function payloadProblem(Channel $channel, mixed $payload): ?string
    {
        if (! is_array($payload)) {
            return 'The request body must be a JSON object.';
        }

        foreach ($channel->requiredFields() as $field) {
            if (! isset($payload[$field]) || ! is_string($payload[$field]) || $payload[$field] === '') {
                return "The field '{$field}' is required and must be a non-empty string; {$channel->value} needs: ".implode(', ', $channel->requiredFields()).'.';
            }
        }

        return null;
    }

    /**
     * @param  array<mixed>  $body
     */
    private function json(int $status, array $body): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($body, JSON_THROW_ON_ERROR);
    }

    private function error(int $status, string $code, string $message): void
    {
        $this->json($status, ['error' => ['code' => $code, 'message' => $message]]);
    }
}
