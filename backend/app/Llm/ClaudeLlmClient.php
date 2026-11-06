<?php

declare(strict_types=1);

namespace App\Llm;

use App\Llm\Exceptions\LlmBadResponse;
use App\Llm\Exceptions\LlmRefused;
use App\Llm\Exceptions\LlmTruncated;
use App\Llm\Exceptions\LlmUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use LogicException;

/**
 * Calls the Anthropic Messages API over Laravel's HTTP client (K9).
 * The API key only goes into the request header; it is never logged or put in a message.
 */
final class ClaudeLlmClient implements LlmClient
{
    public const API_URL = 'https://api.anthropic.com/v1/messages';

    public const API_VERSION = '2026-10-21';

    public const MAX_ATTEMPTS = 3;

    // Used when the API sends no usable retry-after header.
    public const BASE_BACKOFF_SECONDS = 1;

    // A hostile or buggy retry-after must not stall a request for minutes.
    public const MAX_RETRY_DELAY_SECONDS = 30;

    private const STATUS_RATE_LIMITED = 429;

    private const FIRST_SERVER_ERROR_STATUS = 500;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $timeoutSeconds,
    ) {}

    public function complete(LlmRequest $request): LlmResponse
    {
        return $this->toResponse($this->decode($this->send($request)));
    }

    public function stream(LlmRequest $request): iterable
    {
        throw new LogicException('ClaudeLlmClient::stream() is implemented in task 5.3.');
    }

    private function send(LlmRequest $request): Response
    {
        $payload = $this->payload($request);

        for ($attempt = 1; ; $attempt++) {
            $outcome = $this->attempt($payload);

            if ($outcome instanceof Response && ! $this->isRetryable($outcome)) {
                return $this->failOnClientError($outcome);
            }

            if ($attempt === self::MAX_ATTEMPTS) {
                throw new LlmUnavailable($this->unavailableMessage($outcome));
            }

            $delay = $this->retryDelaySeconds($outcome, $attempt);
            Log::warning('Claude API call failed; retrying.', [
                'attempt' => $attempt,
                'status' => $outcome instanceof Response ? $outcome->status() : 'connection_error',
                'delay_seconds' => $delay,
            ]);
            Sleep::for($delay)->seconds();
        }
    }

    /** @param  array<string, mixed>  $payload */
    private function attempt(array $payload): Response|ConnectionException
    {
        try {
            return Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => self::API_VERSION,
            ])->timeout($this->timeoutSeconds)->acceptJson()->post(self::API_URL, $payload);
        } catch (ConnectionException $exception) {
            return $exception;
        }
    }

    /** @return array<string, mixed> */
    private function payload(LlmRequest $request): array
    {
        $payload = [
            'model' => $this->model,
            'max_tokens' => $request->maxTokens,
            'system' => $request->system,
            'messages' => $request->messages,
        ];

        if ($request->jsonSchema !== null) {
            $payload['output_config'] = ['format' => ['type' => 'json_schema', 'schema' => $request->jsonSchema]];
        }

        return $payload;
    }

    private function isRetryable(Response $response): bool
    {
        return $response->status() === self::STATUS_RATE_LIMITED
            || $response->status() >= self::FIRST_SERVER_ERROR_STATUS;
    }

    private function failOnClientError(Response $response): Response
    {
        if ($response->successful()) {
            return $response;
        }

        $reason = $response->json('error.message');

        throw new LlmBadResponse(sprintf(
            'Claude API rejected the request with status %d (%s): %s',
            $response->status(),
            (string) $response->json('error.type', 'unknown_error'),
            is_string($reason) ? $reason : 'no error message in the response body',
        ));
    }

    private function retryDelaySeconds(Response|ConnectionException $outcome, int $attempt): int
    {
        $header = $outcome instanceof Response ? $outcome->header('retry-after') : '';

        if (ctype_digit($header)) {
            return min((int) $header, self::MAX_RETRY_DELAY_SECONDS);
        }

        return min(self::BASE_BACKOFF_SECONDS * 2 ** ($attempt - 1), self::MAX_RETRY_DELAY_SECONDS);
    }

    private function unavailableMessage(Response|ConnectionException $outcome): string
    {
        $last = $outcome instanceof Response
            ? 'status '.$outcome->status()
            : 'a connection error ('.$outcome->getMessage().')';

        return sprintf('Claude API unavailable after %d attempts; last failure: %s.', self::MAX_ATTEMPTS, $last);
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        $body = $response->json();

        return is_array($body)
            ? $body
            : throw new LlmBadResponse('Claude API returned a body that is not a JSON object.');
    }

    /** @param  array<string, mixed>  $body */
    private function toResponse(array $body): LlmResponse
    {
        $stopReason = $body['stop_reason'] ?? null;

        if ($stopReason === LlmResponse::STOP_REFUSAL) {
            $category = $body['stop_details']['category'] ?? null;

            throw new LlmRefused('Claude declined to answer (category: '.(is_string($category) ? $category : 'unspecified').').');
        }

        if ($stopReason === LlmResponse::STOP_MAX_TOKENS) {
            throw new LlmTruncated('Claude hit max_tokens before finishing; raise max_tokens or shorten the task.');
        }

        if (! is_string($stopReason) || ! is_array($body['content'] ?? null)) {
            throw new LlmBadResponse('Claude API response lacks a string "stop_reason" or a "content" array.');
        }

        return new LlmResponse(
            $this->textOf($body['content']),
            new LlmUsage(
                (int) ($body['usage']['input_tokens'] ?? 0),
                (int) ($body['usage']['output_tokens'] ?? 0),
            ),
            $stopReason,
        );
    }

    /** @param  array<mixed>  $blocks */
    private function textOf(array $blocks): string
    {
        $text = '';

        foreach ($blocks as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }

        return $text;
    }
}
