<?php

declare(strict_types=1);

namespace App\Llm\Exceptions;

/** The model hit max_tokens, so the output is cut off and must not be parsed. */
final class LlmTruncated extends LlmException {}
