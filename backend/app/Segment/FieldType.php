<?php

declare(strict_types=1);

namespace App\Segment;

enum FieldType: string
{
    case Enum = 'enum';
    case Number = 'number';
    case Date = 'date';

    /**
     * The operators a field of this type accepts; the parser, prompt and JSON schema all read this.
     *
     * @return list<Operator>
     */
    public function allowedOperators(): array
    {
        return match ($this) {
            self::Enum => [Operator::Eq, Operator::Neq, Operator::In, Operator::NotIn, Operator::IsNull, Operator::NotNull],
            self::Number => [Operator::Eq, Operator::Neq, Operator::Gt, Operator::Gte, Operator::Lt, Operator::Lte, Operator::Between, Operator::IsNull, Operator::NotNull],
            self::Date => [Operator::WithinLastDays, Operator::NotWithinLastDays, Operator::Between, Operator::IsNull, Operator::NotNull],
        };
    }
}
