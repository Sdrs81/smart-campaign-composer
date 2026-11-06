<?php

declare(strict_types=1);

namespace App\Segment;

enum Operator: string
{
    case Eq = 'eq';
    case Neq = 'neq';
    case In = 'in';
    case NotIn = 'not_in';
    case Gt = 'gt';
    case Gte = 'gte';
    case Lt = 'lt';
    case Lte = 'lte';
    case Between = 'between';
    case WithinLastDays = 'within_last_days';
    // Customers whose date is null (it never happened) match too.
    case NotWithinLastDays = 'not_within_last_days';
    case IsNull = 'is_null';
    case NotNull = 'not_null';

    /** is_null / not_null take no value. */
    public function takesValue(): bool
    {
        return $this !== self::IsNull && $this !== self::NotNull;
    }
}
