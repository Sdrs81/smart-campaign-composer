<?php

declare(strict_types=1);

namespace App\Llm;

use LogicException;
use Throwable;

/**
 * Offline client driven by a queue of scenarios, consumed one per call (K9).
 * A scenario is a text, a full response, or a throwable to simulate a failure.
 */
final class FakeLlmClient implements LlmClient
{
    public const STREAM_CHUNK_CHARACTERS = 8;

    /** @var list<string|LlmResponse|Throwable> */
    private array $scenarios = [];

    /** @var list<LlmRequest> */
    private array $requests = [];

    public function enqueue(string|LlmResponse|Throwable ...$scenarios): self
    {
        foreach ($scenarios as $scenario) {
            $this->scenarios[] = $scenario;
        }

        return $this;
    }

    /** @return list<LlmRequest> */
    public function requests(): array
    {
        return $this->requests;
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        return $this->next($request);
    }

    public function stream(LlmRequest $request): iterable
    {
        $response = $this->next($request);
        $pieces = mb_str_split($response->text, self::STREAM_CHUNK_CHARACTERS);
        $lastIndex = count($pieces) - 1;

        foreach ($pieces as $index => $piece) {
            yield $index === $lastIndex
                ? new LlmChunk($piece, $response->stopReason, $response->usage)
                : new LlmChunk($piece);
        }

        if ($pieces === []) {
            yield new LlmChunk('', $response->stopReason, $response->usage);
        }
    }

    private function next(LlmRequest $request): LlmResponse
    {
        $this->requests[] = $request;

        $scenario = array_shift($this->scenarios)
            ?? throw new LogicException('FakeLlmClient has no scenario left for call #'.count($this->requests).'; enqueue one per expected call.');

        if ($scenario instanceof Throwable) {
            throw $scenario;
        }

        return is_string($scenario)
            ? new LlmResponse($scenario, new LlmUsage(0, 0))
            : $scenario;
    }
}
