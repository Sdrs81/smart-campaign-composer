<?php

declare(strict_types=1);

namespace App\Llm\Exceptions;

/** A non-retryable API error (4xx) or a body that does not have the documented shape. */
final class LlmBadResponse extends LlmException {}
