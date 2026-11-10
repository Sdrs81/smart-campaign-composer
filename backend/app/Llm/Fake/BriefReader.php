<?php

declare(strict_types=1);

namespace App\Llm\Fake;

use App\Segment\FieldDefinition;
use App\Segment\FieldRegistry;
use App\Segment\Operator;

/**
 * Reads the audience filters a Turkish brief states in plain words, for the key-less fake (K25). It is a
 * handful of narrow patterns, not language understanding: when a phrase is not clear the filter is left
 * out, because a missing condition is a visible gap while a wrong one silently picks the wrong customers.
 * Allowed values come from the FieldRegistry (K7); every result still goes through SegmentRuleParser.
 *
 * @phpstan-type Condition array{field: string, operator: string, value: mixed}
 */
final class BriefReader
{
    private const TURKISH_TO_ASCII = [
        'İ' => 'i', 'I' => 'i', 'ı' => 'i', 'ş' => 's', 'Ş' => 's', 'ğ' => 'g', 'Ğ' => 'g',
        'ü' => 'u', 'Ü' => 'u', 'ö' => 'o', 'Ö' => 'o', 'ç' => 'c', 'Ç' => 'c',
    ];

    private const DAYS_PER_WEEK = 7;

    private const DAYS_PER_MONTH = 30;

    // How far after an action word a negation still belongs to it ("sipariş vermemiş", "alışveriş yapmayan").
    private const NEGATION_REACH_WORDS = 3;

    private const NUMBER_WORDS = [
        'bir' => 1, 'iki' => 2, 'uc' => 3, 'dort' => 4, 'bes' => 5, 'alti' => 6, 'yedi' => 7,
        'sekiz' => 8, 'dokuz' => 9, 'on' => 10, 'yirmi' => 20, 'otuz' => 30, 'kirk' => 40, 'elli' => 50,
    ];

    // "%20", "20%" or "yüzde 20 / yüzde yirmi"; a discount over 99% is not a discount the brief means.
    private const DISCOUNT_PATTERN = '/%\s*(\d{1,2})(?!\d)|(?<!\d)(\d{1,2})\s*%|yuzde\s+(\d{1,2}|[a-z]+)/';

    private const UNIT_DAYS = ['gun' => 1, 'hafta' => self::DAYS_PER_WEEK, 'ay' => self::DAYS_PER_MONTH];

    // The unit must be the whole word or carry a known suffix: a bare prefix would read "ayakkabı" as "ay".
    private const UNIT_PATTERN = '/^(gun|hafta|ay)(dir|dur|de|da|den|dan|luk|u)?$/';

    private const NEGATION_PATTERN = '/((ma|me)(mis|yan|yen|di|yip|yecek|yacak)|degil)$/';

    private const ACTIONS = ['/^(siparis|alisveris|satin)/' => 'last_purchase_at', '/^giris/' => 'last_login_at', '/^sepet/' => 'last_cart_add_at'];

    private const TIER_WORDS = [
        'bronz' => 'bronze', 'bronze' => 'bronze', 'gumus' => 'silver', 'silver' => 'silver',
        'altin' => 'gold', 'gold' => 'gold', 'platin' => 'platinum', 'platinum' => 'platinum',
    ];

    private const CATEGORY_SYNONYMS = ['teknoloji' => 'elektronik', 'giyim' => 'moda'];

    // A category name this short would match inside unrelated words ("evde"), so it must be the whole word.
    private const MIN_PREFIX_MATCH_LENGTH = 4;

    public function __construct(private readonly FieldRegistry $registry) {}

    /**
     * Mimics a Turkish-aware lowercase: mb_strtolower turns I into i, never ı, so the text is folded to
     * plain ASCII first and compared that way.
     */
    public static function fold(string $text): string
    {
        return strtolower(strtr($text, self::TURKISH_TO_ASCII));
    }

    /** @return list<array{field: string, operator: string, value: mixed}> */
    public function read(string $brief): array
    {
        $folded = self::fold($brief);
        $words = preg_split('/[^a-z0-9]+/', $folded, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter([
            $this->city($folded),
            $this->gender($words),
            $this->age($folded),
            ...$this->activity($words),
            $this->tier($words),
            $this->category($words),
            $this->platform($words),
        ]));
    }

    /**
     * The details a message should repeat, so SMS and email carry the same facts (K25): the discount, a
     * single city and the categories the brief names.
     *
     * @return array{discount: int|null, city: string|null, categories: list<string>}
     */
    public function facts(string $brief): array
    {
        $city = null;
        $categories = [];
        foreach ($this->read($brief) as $condition) {
            if ($condition['field'] === 'city' && $condition['operator'] === 'eq') {
                $city = (string) $condition['value'];
            }
            if ($condition['field'] === 'favorite_category') {
                $categories = (array) $condition['value'];
            }
        }

        return ['discount' => $this->discount(self::fold($brief)), 'city' => $city, 'categories' => $categories];
    }

    private function discount(string $folded): ?int
    {
        if (preg_match(self::DISCOUNT_PATTERN, $folded, $match) !== 1) {
            return null;
        }
        $amount = end($match);
        $percent = ctype_digit($amount) ? (int) $amount : (self::NUMBER_WORDS[$amount] ?? 0);

        return $percent >= 1 && $percent <= 99 ? $percent : null;
    }

    /** One line in the chips' own wording (field label + operator template), for the rule explanation. */
    /** @param  list<Condition>  $conditions */
    public function describe(array $conditions): string
    {
        $parts = array_map(function (array $condition): string {
            $value = $condition['value'];
            $text = is_array($value) ? implode($condition['operator'] === 'between' ? '–' : ', ', $value) : (string) $value;

            return $this->registry->find($condition['field'])?->label.': '
                .str_replace('{value}', $text, Operator::from($condition['operator'])->template());
        }, $conditions);

        return 'Briefte belirtilen koşullara göre müşteriler seçildi: '.implode('; ', $parts).'.';
    }

    /** @return array{field: string, operator: string, value: mixed}|null */
    private function city(string $folded): ?array
    {
        $found = array_values(array_filter(
            $this->values('city'),
            fn (string $city): bool => preg_match('/(?<![a-z0-9])'.preg_quote(self::fold($city), '/').'/', $folded) === 1,
        ));

        return $this->oneOrMany('city', $found);
    }

    /**
     * @param  list<string>  $words
     * @return Condition|null
     */
    private function gender(array $words): ?array
    {
        $male = array_filter($words, fn (string $word): bool => str_starts_with($word, 'erkek'));
        $female = array_filter($words, fn (string $word): bool => str_starts_with($word, 'kadin') || str_starts_with($word, 'bayan'));

        // Both words in one brief ("erkek ve kadın müşteriler") means everyone, so no filter.
        if (($male === []) === ($female === [])) {
            return null;
        }

        return ['field' => 'gender', 'operator' => 'eq', 'value' => $male !== [] ? 'male' : 'female'];
    }

    /** @return Condition|null */
    private function age(string $folded): ?array
    {
        $max = $this->registry->find('age')->max ?? 0;

        if (preg_match('/(?<![a-z0-9])(\d{1,3})\s*(?:-|–|ile)\s*(\d{1,3})\s*yas/u', $folded, $range) === 1
            && (int) $range[1] <= (int) $range[2] && (int) $range[2] <= $max) {
            return ['field' => 'age', 'operator' => 'between', 'value' => [(int) $range[1], (int) $range[2]]];
        }

        if (preg_match('/(?<![a-z0-9])(\d{1,3})\s*yas\w*\s+(ve\s+)?(alti|kucuk|ustu|uzeri|buyuk)/', $folded, $bound) !== 1 || (int) $bound[1] > $max) {
            return null;
        }
        $inclusive = $bound[2] !== '';
        $below = in_array($bound[3], ['alti', 'kucuk'], true);

        return ['field' => 'age', 'operator' => $below ? ($inclusive ? 'lte' : 'lt') : ($inclusive ? 'gte' : 'gt'), 'value' => (int) $bound[1]];
    }

    /**
     * A duration ("1 hafta", "30 gün") applies to the action words nearest to it; the action is negated by
     * a "-mamış/-meyen" word right after it.
     *
     * @param  list<string>  $words
     * @return list<array{field: string, operator: string, value: int}>
     */
    private function activity(array $words): array
    {
        $durations = $this->durations($words);
        $actions = [];
        foreach ($words as $index => $word) {
            foreach (self::ACTIONS as $pattern => $field) {
                if (preg_match($pattern, $word) === 1) {
                    $actions[$index] = $field;
                }
            }
        }
        if ($durations === []) {
            return [];
        }

        $conditions = [];
        foreach ($actions as $index => $field) {
            if (isset($conditions[$field])) {
                continue;
            }
            $nearest = array_reduce(
                array_keys($durations),
                fn (?int $best, int $at): int => $best === null || abs($at - $index) < abs($best - $index) ? $at : $best,
            );
            $conditions[$field] = [
                'field' => $field,
                'operator' => $this->isNegated($words, $index, $actions) ? 'not_within_last_days' : 'within_last_days',
                'value' => $durations[$nearest],
            ];
        }

        return array_values($conditions);
    }

    /**
     * @param  list<string>  $words
     * @return array<int, int> the index of the number word => days, kept inside what the parser allows
     */
    private function durations(array $words): array
    {
        $durations = [];
        foreach ($words as $index => $word) {
            $amount = ctype_digit($word) ? (int) $word : (self::NUMBER_WORDS[$word] ?? null);
            if ($amount === null || ! isset($words[$index + 1]) || preg_match(self::UNIT_PATTERN, $words[$index + 1], $unit) !== 1) {
                continue;
            }
            $days = $amount * self::UNIT_DAYS[$unit[1]];
            $durations[$index] = max(FieldDefinition::MIN_RELATIVE_DAYS, min(FieldDefinition::MAX_RELATIVE_DAYS, $days));
        }

        return $durations;
    }

    /**
     * @param  list<string>  $words
     * @param  array<int, string>  $actions
     */
    private function isNegated(array $words, int $actionIndex, array $actions): bool
    {
        for ($at = $actionIndex + 1; $at <= $actionIndex + self::NEGATION_REACH_WORDS && isset($words[$at]); $at++) {
            // The next action word starts its own phrase ("sepete ürün ekleyip alışveriş yapmayan").
            if (isset($actions[$at])) {
                return false;
            }
            if (preg_match(self::NEGATION_PATTERN, $words[$at]) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $words
     * @return Condition|null
     */
    private function tier(array $words): ?array
    {
        $found = [];
        foreach ($words as $word) {
            $tier = self::TIER_WORDS[preg_replace('/(lar|ler)$/', '', $word)] ?? null;
            if ($tier !== null) {
                $found[$tier] = true;
            }
        }

        return $this->oneOrMany('loyalty_tier', array_values(array_filter($this->values('loyalty_tier'), fn (string $tier): bool => isset($found[$tier]))));
    }

    /**
     * @param  list<string>  $words
     * @return Condition|null
     */
    private function category(array $words): ?array
    {
        $found = [];
        foreach ($this->values('favorite_category') as $category) {
            foreach ($words as $word) {
                $matches = $word === $category
                    || (strlen($category) >= self::MIN_PREFIX_MATCH_LENGTH && str_starts_with($word, $category));
                $synonym = array_search($category, self::CATEGORY_SYNONYMS, true);
                if ($matches || ($synonym !== false && str_starts_with($word, $synonym))) {
                    $found[] = $category;
                    break;
                }
            }
        }

        return $this->oneOrMany('favorite_category', $found);
    }

    /**
     * @param  list<string>  $words
     * @return Condition|null
     */
    private function platform(array $words): ?array
    {
        $found = array_values(array_filter(
            $this->values('platform'),
            fn (string $platform): bool => array_filter($words, fn (string $word): bool => $platform === 'ios' ? $word === 'ios' : str_starts_with($word, $platform)) !== [],
        ));

        return $this->oneOrMany('platform', array_values(array_filter($found, fn (string $platform): bool => $platform !== 'web')));
    }

    /**
     * @param  list<string>  $values
     * @return array{field: string, operator: string, value: mixed}|null
     */
    private function oneOrMany(string $field, array $values): ?array
    {
        return match (count($values)) {
            0 => null,
            1 => ['field' => $field, 'operator' => 'eq', 'value' => $values[0]],
            default => ['field' => $field, 'operator' => 'in', 'value' => $values],
        };
    }

    /** @return list<string> */
    private function values(string $field): array
    {
        return $this->registry->find($field)?->allowedValues() ?? [];
    }
}
