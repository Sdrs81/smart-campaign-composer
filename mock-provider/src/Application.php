<?php

declare(strict_types=1);

namespace MockProvider;

use Closure;
use JsonException;
use Redis;

/**
 * Single entry point: maps method and path to a handler and always answers JSON.
 */
final class Application
{
    private const IDEMPOTENCY_HEADER = 'HTTP_IDEMPOTENCY_KEY';

    private const FAULT_HEADER = 'HTTP_X_MOCK_FAULT';

    private const MICROSECONDS_PER_MILLISECOND = 1000;

    /**
     * @param  Closure(int): void  $sleepMilliseconds  Injected so tests do not wait for latency or timeouts
     */
    public function __construct(
        private readonly Stats $stats,
        private readonly IdempotencyStore $idempotency,
        private readonly RecipientValidator $recipients,
        private readonly FaultPolicy $faults,
        private readonly RateLimiter $rateLimiter,
        private readonly Closure $sleepMilliseconds,
    ) {}

    public static function fromEnvironment(): self
    {
        $redis = new Redis;
        // getenv, not $_ENV: variables_order may omit E under php -S.
        $redis->connect((string) (getenv('REDIS_HOST') ?: 'redis'), (int) (getenv('REDIS_PORT') ?: 6379));

        return new self(
            new Stats($redis),
            new IdempotencyStore($redis),
            new RecipientValidator,
            FaultPolicy::fromEnvironment(new SystemRandom),
            RateLimiter::fromEnvironment($redis),
            static fn (int $milliseconds) => usleep($milliseconds * self::MICROSECONDS_PER_MILLISECOND),
        );
    }

    /**
     * @param  array<string, mixed>  $server  $_SERVER
     */
    public function handle(string $method, string $path, string $rawBody, array $server): Response
    {
        $channel = Channel::tryFrom(ltrim($path, '/'));

        return match (true) {
            $method === 'GET' && $path === '/health' => new Response(200, ['status' => 'ok']),
            $method === 'GET' && $path === '/stats' => new Response(200, $this->stats->snapshot()),
            $method === 'DELETE' && $path === '/stats' => $this->resetStats(),
            $channel !== null && $method === 'POST' => $this->send($channel, $rawBody, $server),
            $channel !== null => $this->error(405, 'method_not_allowed', "{$method} {$path} is not supported; use POST."),
            default => $this->error(404, 'not_found', "No route for {$method} {$path}."),
        };
    }

    /**
     * @param  array<string, mixed>  $server
     */
    private function send(Channel $channel, string $rawBody, array $server): Response
    {
        $key = $server[self::IDEMPOTENCY_HEADER] ?? '';
        if (! is_string($key) || $key === '') {
            return $this->error(400, 'idempotency_key_required', 'The Idempotency-Key header is required and must not be empty.');
        }

        $forcedFault = null;
        if (isset($server[self::FAULT_HEADER])) {
            $forcedFault = is_string($server[self::FAULT_HEADER]) ? Fault::tryFrom($server[self::FAULT_HEADER]) : null;
            if ($forcedFault === null) {
                return $this->error(400, 'invalid_fault', 'The X-Mock-Fault header must be one of: '.implode(', ', array_column(Fault::cases(), 'value')).'.');
            }
        }

        try {
            $payload = json_decode($rawBody, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return $this->error(400, 'invalid_json', 'The request body must be a JSON object: '.$exception->getMessage());
        }

        $problem = $this->payloadProblem($channel, $payload);
        if ($problem !== null) {
            return $this->error(400, 'invalid_payload', $problem);
        }

        if (! $this->recipients->isValid($channel, $payload['to'])) {
            return $this->error(400, 'invalid_recipient', "'{$payload['to']}' is not a valid {$channel->value} recipient.");
        }

        // One clock reading for the whole request: the limiter and the peak counter must agree on the second,
        // or latency would move an accepted message into the next second's bucket and show a false burst.
        $arrivedAt = time();

        if (! $this->rateLimiter->tryAcquire($channel, $arrivedAt)) {
            return $this->rateLimited($channel, RateLimitCause::Limit, "The {$channel->value} channel is over its limit per second; retry later.");
        }

        return $this->deliver($channel, $key, $payload, $this->faults->decide($forcedFault), $arrivedAt);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function deliver(Channel $channel, string $key, array $payload, FaultDecision $decision, int $arrivedAt): Response
    {
        ($this->sleepMilliseconds)($decision->latencyMilliseconds);

        switch ($decision->fault) {
            case Fault::RateLimited:
                return $this->rateLimited($channel, RateLimitCause::Injected, 'Injected fault: the provider asked the client to slow down.');
            case Fault::ServerError:
                return $this->error(500, 'provider_error', 'Injected fault: the provider failed before accepting the message.');
            case Fault::Timeout:
                return $this->error(504, 'timeout', 'Injected fault: the provider answered after the client gave up.');
            case Fault::None:
            case Fault::AcceptThenServerError:
                break;
        }

        $result = $this->idempotency->remember(
            $channel,
            $key,
            $payload,
            ['id' => bin2hex(random_bytes(16)), 'status' => 'accepted'],
        );

        if ($result->outcome === IdempotencyOutcome::Conflict) {
            return $this->error(422, 'idempotency_key_reused', "The Idempotency-Key '{$key}' was already used with a different {$channel->value} request body.");
        }

        if ($result->outcome === IdempotencyOutcome::Created) {
            $this->stats->recordAccepted($channel, $arrivedAt);
        } else {
            $this->stats->recordDuplicate($channel);
        }

        if ($decision->fault === Fault::AcceptThenServerError) {
            return $this->error(500, 'provider_error', 'Injected fault: the message was accepted, but the provider answered 500.');
        }

        return new Response(200, $result->response);
    }

    private function resetStats(): Response
    {
        $this->stats->reset();
        $this->idempotency->forgetAll();

        return new Response(200, ['status' => 'reset']);
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

    private function rateLimited(Channel $channel, RateLimitCause $cause, string $message): Response
    {
        $this->stats->recordRateLimited($channel, $cause);

        return $this->error(429, 'rate_limited', $message, ['Retry-After' => (string) RateLimiter::RETRY_AFTER_SECONDS]);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function error(int $status, string $code, string $message, array $headers = []): Response
    {
        return new Response($status, ['error' => ['code' => $code, 'message' => $message]], $headers);
    }
}
