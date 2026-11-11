<?php

declare(strict_types=1);

namespace App\Llm;

use App\Enums\LlmCallOutcome;
use App\Models\LlmCall;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Decorator that writes one llm_calls row per call and leaves the wrapped client untouched.
 * Exactly one row per call: a stream is recorded when it ends, fails or is abandoned.
 */
final class RecordingLlmClient implements LlmClient
{
    private const NANOSECONDS_PER_MILLISECOND = 1_000_000;

    private ?LlmCall $lastCall = null;

    public function __construct(
        private readonly LlmClient $inner,
        private readonly string $model,
    ) {}

    /** The row of the most recent call, so the caller can mark it invalid after validating the answer. */
    public function lastCall(): ?LlmCall
    {
        return $this->lastCall;
    }

    /** @param  list<string>  $errors  path-based validation messages for the most recent call */
    public function markLastCallInvalid(array $errors): void
    {
        if ($this->lastCall === null) {
            return;
        }

        // Best-effort like record(): failing to annotate the log must not discard the answer being repaired.
        try {
            $this->lastCall->markInvalid($errors);
            $this->lastCall->save();
            $this->log($this->lastCall);
        } catch (QueryException $exception) {
            report($exception);
        }
    }

    /** The prompt, the brief and the raw answer stay in the table only; the line carries ids, sizes and the outcome. */
    private function log(LlmCall $call): void
    {
        Log::log($call->outcome === LlmCallOutcome::Succeeded ? 'info' : 'warning', "llm.call.{$call->outcome->value}", [
            'llm_call_id' => $call->id,
            'purpose' => $call->purpose,
            'attempt' => $call->attempt,
            'model' => $call->model,
            'latency_ms' => $call->latency_ms,
            'input_tokens' => $call->input_tokens,
            'output_tokens' => $call->output_tokens,
            'error' => $call->error_message,
            'validation_errors' => $call->validation_errors,
        ]);
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $startedAt = hrtime(true);

        try {
            $response = $this->inner->complete($request);
        } catch (Throwable $failure) {
            $this->record($request, $startedAt, LlmCallOutcome::Failed, error: $failure);

            throw $failure;
        }

        $this->record($request, $startedAt, LlmCallOutcome::Succeeded, $response->usage, $response->text);

        return $response;
    }

    public function stream(LlmRequest $request): iterable
    {
        $startedAt = hrtime(true);
        $text = '';
        $usage = null;
        $error = null;
        $outcome = LlmCallOutcome::Aborted;

        try {
            foreach ($this->inner->stream($request) as $chunk) {
                $text .= $chunk->text;
                $usage = $chunk->usage ?? $usage;
                // Set before yielding: a consumer that stops right after the last chunk still finished the stream.
                if ($chunk->isLast()) {
                    $outcome = LlmCallOutcome::Succeeded;
                }

                yield $chunk;
            }
        } catch (Throwable $failure) {
            $outcome = LlmCallOutcome::Failed;
            $error = $failure;

            throw $failure;
        } finally {
            $this->record($request, $startedAt, $outcome, $usage, $text, $error);
        }
    }

    private function record(
        LlmRequest $request,
        int $startedAt,
        LlmCallOutcome $outcome,
        ?LlmUsage $usage = null,
        ?string $rawOutput = null,
        ?Throwable $error = null,
    ): void {
        // Logging is best-effort: a failed write must not discard a paid answer or hide the original error.
        try {
            $this->lastCall = $this->createRow($request, $startedAt, $outcome, $usage, $rawOutput, $error);
            $this->log($this->lastCall);
        } catch (QueryException $exception) {
            $this->lastCall = null;
            report($exception);
        }
    }

    private function createRow(
        LlmRequest $request,
        int $startedAt,
        LlmCallOutcome $outcome,
        ?LlmUsage $usage,
        ?string $rawOutput,
        ?Throwable $error,
    ): LlmCall {
        return LlmCall::create([
            'purpose' => $request->purpose,
            'prompt_version' => $request->promptVersion,
            'model' => $this->model,
            'input_tokens' => $usage?->inputTokens,
            'output_tokens' => $usage?->outputTokens,
            // Rounded up so a real call is never logged as 0 ms.
            'latency_ms' => (int) ceil((hrtime(true) - $startedAt) / self::NANOSECONDS_PER_MILLISECOND),
            'attempt' => $request->attempt,
            'outcome' => $outcome,
            'error_message' => $error === null ? null : $error::class.': '.$error->getMessage(),
            'raw_output' => $rawOutput === '' ? null : $rawOutput,
        ]);
    }
}
