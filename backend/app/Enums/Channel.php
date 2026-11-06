<?php

declare(strict_types=1);

namespace App\Enums;

enum Channel: string
{
    case Sms = 'sms';
    case Email = 'email';
}
