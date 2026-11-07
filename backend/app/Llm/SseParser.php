<?php

declare(strict_types=1);

namespace App\Llm;

use App\Llm\Exceptions\LlmBadResponse;
use App\Llm\Exceptions\LlmUnavailable;
use JsonException;

/**
 * Turns the raw bytes of an Anthropic Messages SSE stream into LlmChunks.
 * Pure and incremental: feed() accepts pieces cut at any byte, even in the middle of a
 * line or a multibyte character, and returns the chunks that became complete.
 */
final class SseParser
{
    private const OVERLOADED_ERROR_TYPE = 'overloaded_error';

    private const SNIPPET_LENGTH = 80;

    private string $buffer = '';

    private string $eventName = '';

    /** @var list<string> */
    private array $dataLines = [];

    private int $inputTokens = 0;

    private int $outputTokens = 0;

    private ?string $stopReason = null;

    private bool $completed = false;

    /** @return list<LlmChunk> */
    public function feed(string $piece): array
    {
        $this->buffer .= $piece;
        $chunks = [];

        while (($lineEnd = strpos($this->buffer, "\n")) !== false) {
            $line = rtrim(substr($this->buffer, 0, $lineEnd), "\r");
            $this->buffer = substr($this->buffer, $lineEnd + 1);

            $chunk = $this->consumeLine($line);
            if ($chunk !== null) {
                $chunks[] = $chunk;
            }
        }

        return $chunks;
    }

    /**
     * Call when the connection closes: handles a last event without a trailing blank line
     * and fails if the stream ended before message_stop.
     *
     * @return list<LlmChunk>
     */
    public function finish(): array
    {
        $chunks = $this->feed($this->buffer === '' ? '' : "\n");
        $last = $this->dispatch();
        if ($last !== null) {
            $chunks[] = $last;
        }

        return $this->completed
            ? $chunks
            : throw new LlmBadResponse('Claude stream ended before the message_stop event; the output is incomplete.');
    }

    private function consumeLine(string $line): ?LlmChunk
    {
        if ($line === '') {
            return $this->dispatch();
        }

        // Lines starting with ":" are SSE comments.
        if ($line[0] === ':') {
            return null;
        }

        [$field, $value] = array_pad(explode(':', $line, 2), 2, '');
        $value = ltrim($value, ' ');

        match ($field) {
            'event' => $this->eventName = $value,
            'data' => $this->dataLines[] = $value,
            default => null,
        };

        return null;
    }

    private function dispatch(): ?LlmChunk
    {
        $eventName = $this->eventName;
        $data = implode("\n", $this->dataLines);
        $this->eventName = '';
        $this->dataLines = [];

        if ($data === '') {
            return null;
        }

        return $this->handle($eventName, $this->decode($eventName, $data));
    }

    /** @return array<string, mixed> */
    private function decode(string $eventName, string $data): array
    {
        try {
            $decoded = json_decode($data, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new LlmBadResponse(sprintf(
                'Claude stream event "%s" has invalid JSON data (%s): %s',
                $eventName,
                $exception->getMessage(),
                mb_substr($data, 0, self::SNIPPET_LENGTH),
            ));
        }

        return is_array($decoded)
            ? $decoded
            : throw new LlmBadResponse('Claude stream event "'.$eventName.'" data is not a JSON object.');
    }

    /** @param  array<string, mixed>  $payload */
    private function handle(string $eventName, array $payload): ?LlmChunk
    {
        switch ($eventName) {
            case 'error':
                throw $this->errorFrom($payload);
            case 'message_start':
                $this->inputTokens = (int) ($payload['message']['usage']['input_tokens'] ?? 0);

                return null;
            case 'content_block_delta':
                return $this->textDelta($payload);
            case 'message_delta':
                $stopReason = $payload['delta']['stop_reason'] ?? null;
                if ($stopReason !== null && ! is_string($stopReason)) {
                    throw new LlmBadResponse('Claude stream sent a stop_reason that is not a string.');
                }
                $this->stopReason = $stopReason ?? $this->stopReason;
                $this->outputTokens = (int) ($payload['usage']['output_tokens'] ?? $this->outputTokens);

                return null;
            case 'message_stop':
                return $this->finalChunk();
            default:
                // ping, content_block_start/stop and unknown future events carry no text.
                return null;
        }
    }

    /** @param  array<string, mixed>  $payload */
    private function textDelta(array $payload): ?LlmChunk
    {
        $delta = $payload['delta'] ?? [];

        // Thinking and tool-input deltas share this event but are not part of the answer.
        if (($delta['type'] ?? null) !== 'text_delta' || ! is_string($delta['text'] ?? null)) {
            return null;
        }

        return new LlmChunk($delta['text']);
    }

    private function finalChunk(): LlmChunk
    {
        if ($this->stopReason === null) {
            throw new LlmBadResponse('Claude stream sent message_stop without a stop_reason in message_delta.');
        }

        $this->completed = true;

        return new LlmChunk('', $this->stopReason, new LlmUsage($this->inputTokens, $this->outputTokens));
    }

    /** @param  array<string, mixed>  $payload */
    private function errorFrom(array $payload): LlmBadResponse|LlmUnavailable
    {
        $type = (string) ($payload['error']['type'] ?? 'unknown_error');
        $message = sprintf(
            'Claude stream failed with %s: %s',
            $type,
            (string) ($payload['error']['message'] ?? 'no error message'),
        );

        return $type === self::OVERLOADED_ERROR_TYPE
            ? new LlmUnavailable($message)
            : new LlmBadResponse($message);
    }
}
