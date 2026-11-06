<?php

declare(strict_types=1);

namespace App\Llm\Exceptions;

use App\Exceptions\ApiException;

/** The brief could not be turned into a valid rule. Carries every remaining problem, path first. */
final class SegmentExtractionFailed extends ApiException
{
    /**
     * @param  non-empty-list<string>  $errors  e.g. "conditions[1].value: must be a number"
     * @param  list<string>  $unsupported  parts of the brief that no field can express
     * @param  int  $attempts  model calls made; 0 when the brief was rejected before any call
     */
    public function __construct(string $message, array $errors, array $unsupported, int $attempts)
    {
        parent::__construct($message, [
            'errors' => $errors,
            'unsupported_criteria' => $unsupported,
            'attempts' => $attempts,
        ]);
    }

    public function errorCode(): string
    {
        return 'segment_extraction_failed';
    }

    public function status(): int
    {
        return 422;
    }
}
