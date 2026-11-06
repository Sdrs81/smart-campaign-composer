<?php

declare(strict_types=1);

namespace App\Llm\Exceptions;

use RuntimeException;

/** Common parent so callers can catch every LLM failure with one type. */
abstract class LlmException extends RuntimeException {}
