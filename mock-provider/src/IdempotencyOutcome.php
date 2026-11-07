<?php

declare(strict_types=1);

namespace MockProvider;

enum IdempotencyOutcome
{
    case Created;
    case Replayed;
    case Conflict;
}
