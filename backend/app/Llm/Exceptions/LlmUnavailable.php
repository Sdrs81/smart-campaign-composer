<?php

declare(strict_types=1);

namespace App\Llm\Exceptions;

/** The API stayed unreachable or overloaded (429, 5xx, 529, network) after every attempt. */
final class LlmUnavailable extends LlmException {}
