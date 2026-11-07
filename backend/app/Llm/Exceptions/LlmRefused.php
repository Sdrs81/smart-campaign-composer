<?php

declare(strict_types=1);

namespace App\Llm\Exceptions;

/** The model declined to answer (stop_reason "refusal"). */
final class LlmRefused extends LlmException {}
