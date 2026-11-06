<?php

declare(strict_types=1);

namespace App\Enums;

enum MessageSource: string
{
    case Llm = 'llm';
    case Human = 'human';
}
