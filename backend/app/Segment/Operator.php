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

    /**
     * How a chip reads in Turkish; `{value}` is replaced by the condition's value (a list is joined, a
     * range is "a–b"). Kept here, next to the operators, so the UI keeps no copy of them.
     */
    public function template(): string
    {
        return match ($this) {
            self::Eq => '{value}',
            self::Neq => '{value} hariç',
            self::In => '{value}',
            self::NotIn => '{value} hariç',
            self::Gt => '> {value}',
            self::Gte => '≥ {value}',
            self::Lt => '< {value}',
            self::Lte => '≤ {value}',
            self::Between => '{value}',
            self::WithinLastDays => 'son {value} gün içinde',
            self::NotWithinLastDays => 'son {value} gündür yok',
            self::IsNull => 'boş',
            self::NotNull => 'dolu',
        };
    }

    /** is_null / not_null take no value. */
    public function takesValue(): bool
    {
        return $this !== self::IsNull && $this !== self::NotNull;
    }
}
