<?php

declare(strict_types=1);

namespace App\Segment;

use Closure;

final readonly class FieldDefinition
{
    public const MAX_IN_VALUES = 50;

    public const MIN_RELATIVE_DAYS = 1;

    public const MAX_RELATIVE_DAYS = 365;

    /**
     * @param  (Closure(): list<string>)|null  $enumValues  lazy, so the city list is read from the DB only when needed
     * @param  bool  $derivedFromBirthDate  age is not stored; the compiler turns it into a birth_date comparison
     */
    public function __construct(
        public string $name,
        public FieldType $type,
        public string $column,
        public string $label,
        private ?Closure $enumValues = null,
        public ?int $min = null,
        public ?int $max = null,
        public bool $derivedFromBirthDate = false,
    ) {}

    /** @return list<string> */
    public function allowedValues(): array
    {
        return $this->enumValues === null ? [] : ($this->enumValues)();
    }

    /** @return list<Operator> */
    public function allowedOperators(): array
    {
        return $this->type->allowedOperators();
    }

    public function allows(Operator $operator): bool
    {
        return in_array($operator, $this->allowedOperators(), true);
    }
}
