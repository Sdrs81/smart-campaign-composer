<?php

declare(strict_types=1);

namespace App\Segment;

use App\Enums\Channel;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Turns a parsed SegmentRule into a query on customers. Column names come only from FieldRegistry
 * definitions and every rule value is a binding, so nothing from the LLM reaches the SQL text.
 */
final class SegmentQueryCompiler
{
    /** @return Builder<Customer> */
    public function compile(SegmentRule $rule): Builder
    {
        $query = Customer::query();

        // One fixed "now" per query keeps every relative-date condition consistent.
        $now = CarbonImmutable::now();

        foreach ($rule->conditions as $condition) {
            $condition->field->derivedFromBirthDate
                ? $this->applyAge($query, $condition, $now)
                : $this->applyColumn($query, $condition, $now);
        }

        return $query;
    }

    /**
     * Second opt-in check (CLAUDE.md invariant): consent and a usable address, same as Customer::canReceive().
     *
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    public function withChannelConsent(Builder $query, Channel $channel): Builder
    {
        return $query->whereRaw($this->consentSql($channel));
    }

    /**
     * The one definition of "may receive this channel" as a SQL condition; AudienceService uses it in
     * COUNT(*) FILTER so counting and filtering cannot disagree. It holds no user input.
     */
    public function consentSql(Channel $channel): string
    {
        [$optInColumn, $addressColumn] = match ($channel) {
            Channel::Sms => ['sms_opt_in', 'phone'],
            Channel::Email => ['email_opt_in', 'email'],
        };

        return "({$optInColumn} AND {$addressColumn} IS NOT NULL AND {$addressColumn} <> '')";
    }

    /** @param  Builder<Customer>  $query */
    private function applyColumn(Builder $query, Condition $condition, CarbonImmutable $now): void
    {
        $column = $condition->field->column;
        $value = $condition->value;

        // "not" operators keep customers whose value is null (never bought, no category), like not_within_last_days.
        match ($condition->operator) {
            Operator::Eq => $query->where($column, '=', $value),
            Operator::Neq => $query->where(fn (Builder $group) => $group->whereNull($column)->orWhere($column, '<>', $value)),
            Operator::In => $query->whereIn($column, $value),
            Operator::NotIn => $query->where(fn (Builder $group) => $group->whereNull($column)->orWhereNotIn($column, $value)),
            Operator::Gt => $query->where($column, '>', $value),
            Operator::Gte => $query->where($column, '>=', $value),
            Operator::Lt => $query->where($column, '<', $value),
            Operator::Lte => $query->where($column, '<=', $value),
            Operator::Between => $query->whereBetween($column, $this->betweenBounds($condition)),
            Operator::WithinLastDays => $query->where($column, '>=', $now->subDays($value)),
            Operator::NotWithinLastDays => $query->where(fn (Builder $group) => $group->whereNull($column)->orWhere($column, '<', $now->subDays($value))),
            Operator::IsNull => $query->whereNull($column),
            Operator::NotNull => $query->whereNotNull($column),
        };
    }

    /** @return array{0: mixed, 1: mixed} */
    private function betweenBounds(Condition $condition): array
    {
        [$from, $to] = $condition->value;
        if ($condition->field->type !== FieldType::Date) {
            return [$from, $to];
        }

        // Dates are whole days: from 00:00 of the first day to the end of the last day.
        return [CarbonImmutable::parse($from)->startOfDay(), CarbonImmutable::parse($to)->endOfDay()];
    }

    /**
     * Age is not stored. "At least N years old" means born on or before today minus N years;
     * "at most M years old" means born after today minus (M+1) years.
     *
     * @param  Builder<Customer>  $query
     */
    private function applyAge(Builder $query, Condition $condition, CarbonImmutable $now): void
    {
        $column = $condition->field->column;
        $value = $condition->value;
        $today = $now->startOfDay();

        if ($condition->operator === Operator::IsNull || $condition->operator === Operator::NotNull) {
            $condition->operator === Operator::IsNull ? $query->whereNull($column) : $query->whereNotNull($column);

            return;
        }

        // Fractional ages round inward, so "age > 30.5" means 31 and over.
        [$minAge, $maxAge] = match ($condition->operator) {
            Operator::Eq, Operator::Neq => [(int) ceil($value), (int) floor($value)],
            Operator::Gt => [(int) floor($value) + 1, null],
            Operator::Gte => [(int) ceil($value), null],
            Operator::Lt => [null, (int) ceil($value) - 1],
            Operator::Lte => [null, (int) floor($value)],
            Operator::Between => [(int) ceil($value[0]), (int) floor($value[1])],
            default => [null, null],
        };

        $bounds = function (Builder $group) use ($column, $today, $minAge, $maxAge): void {
            // Nobody is younger than 0, and "at most -1" must not turn into "born after today".
            $nobodyFits = $maxAge !== null && ($maxAge < 0 || ($minAge !== null && $minAge > $maxAge));
            if ($nobodyFits) {
                $group->whereRaw('1 = 0');

                return;
            }
            if ($minAge !== null) {
                $group->where($column, '<=', $today->subYearsNoOverflow($minAge)->toDateString());
            }
            if ($maxAge !== null) {
                $group->where($column, '>', $today->subYearsNoOverflow($maxAge + 1)->toDateString());
            }
        };

        $condition->operator === Operator::Neq ? $query->whereNot($bounds) : $query->where($bounds);
    }
}
