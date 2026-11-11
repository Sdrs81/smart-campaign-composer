<?php

declare(strict_types=1);

namespace App\Enums;

enum LlmCallOutcome: string
{
    case Succeeded = 'succeeded';
    // The API call failed (unavailable, truncated, refused, bad response).
    case Failed = 'failed';
    // The answer arrived but did not pass validation.
    case Invalid = 'invalid';
    // The caller stopped reading a stream before it finished, for example a closed browser tab.
    case Aborted = 'aborted';
}
