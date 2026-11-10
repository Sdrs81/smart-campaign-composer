<?php

declare(strict_types=1);

namespace App\Delivery;

use App\Enums\Channel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final readonly class HttpMessageProvider implements MessageProvider
{
    private const IDEMPOTENCY_HEADER = 'Idempotency-Key';

    public function __construct(
        private string $baseUrl,
        private float $connectTimeoutSeconds,
        private float $timeoutSeconds,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (string) config('delivery.provider.url'),
            (float) config('delivery.provider.connect_timeout'),
            (float) config('delivery.provider.timeout'),
        );
    }

    public function send(Channel $channel, array $payload, string $idempotencyKey): SendResult
    {
        try {
            $response = Http::baseUrl($this->baseUrl)
                ->connectTimeout($this->connectTimeoutSeconds)
                ->timeout($this->timeoutSeconds)
                ->acceptJson()
                ->withHeaders([self::IDEMPOTENCY_HEADER => $idempotencyKey])
                ->post($channel->value, $payload);
        } catch (ConnectionException $exception) {
            return SendResult::transient("Could not reach the provider: {$exception->getMessage()}", DeliveryErrorCode::ProviderUnreachable);
        }

        return $this->classify($response);
    }

    private function classify(Response $response): SendResult
    {
        $status = $response->status();
        $reason = "Provider answered {$status}: {$response->body()}";

        return match (true) {
            $response->successful() => SendResult::sent((string) $response->json('id')),
            $status === 429 => SendResult::rateLimited($this->retryAfterSeconds($response), $reason),
            $response->serverError() => SendResult::transient($reason),
            default => SendResult::permanent($reason),
        };
    }

    private function retryAfterSeconds(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        return ctype_digit($header) ? (int) $header : null;
    }
}
