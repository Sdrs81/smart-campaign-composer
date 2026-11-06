<?php

declare(strict_types=1);

namespace App\Segment;

/**
 * Parse, don't validate (K7): the only way to get a SegmentRule from untrusted input such as LLM output.
 * Every problem is collected, not just the first, so one repair round can fix them all.
 */
final class SegmentRuleParser
{
    private const DATE_FORMAT = '/^(\d{4})-(\d{2})-(\d{2})$/';

    private const BETWEEN_BOUNDS = 2;

    private const ROOT_KEYS = ['version', 'conditions'];

    private const CONDITION_KEYS = ['field', 'operator', 'value'];

    public function __construct(private readonly FieldRegistry $registry) {}

    /** @param  array<mixed>  $input */
    public function parse(array $input): SegmentRule|ValidationErrors
    {
        $errors = [];

        $this->checkUnknownKeys($input, self::ROOT_KEYS, '', $errors);

        if (($input['version'] ?? null) !== SegmentRule::VERSION) {
            $errors[] = $this->error('version', 'must be the integer '.SegmentRule::VERSION.'.');
        }

        $conditions = $this->parseConditions($input['conditions'] ?? null, $errors);

        if ($errors !== [] || $conditions === []) {
            return new ValidationErrors($errors);
        }

        return new SegmentRule(SegmentRule::VERSION, $conditions);
    }

    /**
     * @param  list<array{path: string, message: string}>  $errors
     * @return list<Condition>
     */
    private function parseConditions(mixed $raw, array &$errors): array
    {
        if (! is_array($raw) || ! array_is_list($raw)) {
            $errors[] = $this->error('conditions', 'must be a list of conditions.');

            return [];
        }

        $count = count($raw);
        if ($count < SegmentRule::MIN_CONDITIONS || $count > SegmentRule::MAX_CONDITIONS) {
            $errors[] = $this->error('conditions', "must have {$this->range(SegmentRule::MIN_CONDITIONS, SegmentRule::MAX_CONDITIONS)} conditions, got {$count}.");

            return [];
        }

        $conditions = [];
        foreach ($raw as $index => $item) {
            $condition = $this->parseCondition($item, "conditions[{$index}]", $errors);
            if ($condition !== null) {
                $conditions[] = $condition;
            }
        }

        return $conditions;
    }

    /** @param  list<array{path: string, message: string}>  $errors */
    private function parseCondition(mixed $item, string $path, array &$errors): ?Condition
    {
        if (! is_array($item) || array_is_list($item)) {
            $errors[] = $this->error($path, 'must be an object with field, operator and value.');

            return null;
        }

        $before = count($errors);
        $this->checkUnknownKeys($item, self::CONDITION_KEYS, $path.'.', $errors);

        $field = $this->parseField($item['field'] ?? null, $path, $errors);
        $operator = $field === null ? null : $this->parseOperator($item['operator'] ?? null, $field, $path, $errors);
        if ($field !== null && $operator !== null) {
            $this->checkValue($field, $operator, $item['value'] ?? null, "{$path}.value", $errors);
        }

        if ($field === null || $operator === null || count($errors) > $before) {
            return null;
        }

        return new Condition($field, $operator, $item['value'] ?? null);
    }

    /** @param  list<array{path: string, message: string}>  $errors */
    private function parseField(mixed $name, string $path, array &$errors): ?FieldDefinition
    {
        $field = is_string($name) ? $this->registry->find($name) : null;
        if ($field === null) {
            $shown = is_string($name) ? "'{$name}'" : get_debug_type($name);
            $errors[] = $this->error("{$path}.field", "unknown field {$shown}; allowed: ".implode(', ', array_keys($this->registry->all())).'.');
        }

        return $field;
    }

    /** @param  list<array{path: string, message: string}>  $errors */
    private function parseOperator(mixed $name, FieldDefinition $field, string $path, array &$errors): ?Operator
    {
        $operator = is_string($name) ? Operator::tryFrom($name) : null;
        if ($operator !== null && $field->allows($operator)) {
            return $operator;
        }

        $shown = is_string($name) ? "'{$name}'" : get_debug_type($name);
        $allowed = implode(', ', array_map(fn (Operator $allowed): string => $allowed->value, $field->allowedOperators()));
        $errors[] = $this->error("{$path}.operator", "operator {$shown} is not allowed for {$field->type->value} field '{$field->name}'; allowed: {$allowed}.");

        return null;
    }

    /** @param  list<array{path: string, message: string}>  $errors */
    private function checkValue(FieldDefinition $field, Operator $operator, mixed $value, string $path, array &$errors): void
    {
        if (! $operator->takesValue()) {
            if ($value !== null) {
                $errors[] = $this->error($path, "operator '{$operator->value}' takes no value.");
            }

            return;
        }

        match ($operator) {
            Operator::In, Operator::NotIn => $this->checkList($field, $operator, $value, $path, $errors),
            Operator::Between => $this->checkBetween($field, $value, $path, $errors),
            Operator::WithinLastDays, Operator::NotWithinLastDays => $this->checkDays($value, $path, $errors),
            default => $this->addIfError($this->scalarError($field, $value), $path, $errors),
        };
    }

    /** @param  list<array{path: string, message: string}>  $errors */
    private function checkList(FieldDefinition $field, Operator $operator, mixed $value, string $path, array &$errors): void
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $errors[] = $this->error($path, "operator '{$operator->value}' needs a list of values.");

            return;
        }

        $count = count($value);
        if ($count < 1 || $count > FieldDefinition::MAX_IN_VALUES) {
            $errors[] = $this->error($path, "must have {$this->range(1, FieldDefinition::MAX_IN_VALUES)} items, got {$count}.");

            return;
        }

        foreach ($value as $index => $item) {
            $this->addIfError($this->scalarError($field, $item), "{$path}[{$index}]", $errors);
        }
    }

    /** @param  list<array{path: string, message: string}>  $errors */
    private function checkBetween(FieldDefinition $field, mixed $value, string $path, array &$errors): void
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) !== self::BETWEEN_BOUNDS) {
            $errors[] = $this->error($path, "operator 'between' needs a list of exactly ".self::BETWEEN_BOUNDS.' values [from, to].');

            return;
        }

        $before = count($errors);
        foreach ($value as $index => $bound) {
            $this->addIfError($this->scalarError($field, $bound), "{$path}[{$index}]", $errors);
        }

        if (count($errors) === $before && $value[0] > $value[1]) {
            $errors[] = $this->error($path, "'from' ({$value[0]}) must not be greater than 'to' ({$value[1]}).");
        }
    }

    /** @param  list<array{path: string, message: string}>  $errors */
    private function checkDays(mixed $value, string $path, array &$errors): void
    {
        $inRange = is_int($value) && $value >= FieldDefinition::MIN_RELATIVE_DAYS && $value <= FieldDefinition::MAX_RELATIVE_DAYS;
        if (! $inRange) {
            $errors[] = $this->error($path, "must be a whole number of days from {$this->range(FieldDefinition::MIN_RELATIVE_DAYS, FieldDefinition::MAX_RELATIVE_DAYS)}, got ".$this->describe($value).'.');
        }
    }

    /** The error for one value of the field's type, or null when it fits. */
    private function scalarError(FieldDefinition $field, mixed $value): ?string
    {
        return match ($field->type) {
            FieldType::Enum => $this->enumError($field, $value),
            FieldType::Number => $this->numberError($field, $value),
            FieldType::Date => $this->dateError($value),
        };
    }

    private function enumError(FieldDefinition $field, mixed $value): ?string
    {
        $allowed = $field->allowedValues();
        if (is_string($value) && in_array($value, $allowed, true)) {
            return null;
        }

        return 'must be one of '.implode(', ', $allowed)." for '{$field->name}', got ".$this->describe($value).'.';
    }

    private function numberError(FieldDefinition $field, mixed $value): ?string
    {
        if (! is_int($value) && ! is_float($value)) {
            return 'must be a number, got '.$this->describe($value).'.';
        }

        if ($value < $field->min || $value > $field->max) {
            return "must be from {$this->range((int) $field->min, (int) $field->max)} for '{$field->name}', got {$value}.";
        }

        return null;
    }

    private function dateError(mixed $value): ?string
    {
        $valid = is_string($value)
            && preg_match(self::DATE_FORMAT, $value, $parts) === 1
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);

        return $valid ? null : 'must be a date written YYYY-MM-DD, got '.$this->describe($value).'.';
    }

    /**
     * @param  array<mixed>  $input
     * @param  list<string>  $allowedKeys
     * @param  list<array{path: string, message: string}>  $errors
     */
    private function checkUnknownKeys(array $input, array $allowedKeys, string $prefix, array &$errors): void
    {
        foreach (array_diff(array_keys($input), $allowedKeys) as $key) {
            $errors[] = $this->error($prefix.$key, 'unknown key; allowed: '.implode(', ', $allowedKeys).'.');
        }
    }

    /** @param  list<array{path: string, message: string}>  $errors */
    private function addIfError(?string $message, string $path, array &$errors): void
    {
        if ($message !== null) {
            $errors[] = $this->error($path, $message);
        }
    }

    /** @return array{path: string, message: string} */
    private function error(string $path, string $message): array
    {
        return ['path' => $path, 'message' => $message];
    }

    private function range(int $min, int $max): string
    {
        return "{$min}-{$max}";
    }

    private function describe(mixed $value): string
    {
        return is_scalar($value) ? var_export($value, true) : get_debug_type($value);
    }
}
