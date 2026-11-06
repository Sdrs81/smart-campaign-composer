<?php

declare(strict_types=1);

namespace App\Llm;

interface LlmClient
{
    public function complete(LlmRequest $request): LlmResponse;

    /**
     * @return iterable<LlmChunk> the last chunk carries the stop reason and usage
     */
    public function stream(LlmRequest $request): iterable;
}
