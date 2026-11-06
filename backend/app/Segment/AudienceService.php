<?php

declare(strict_types=1);

namespace App\Segment;

use App\Enums\Channel;

/** Counts the audience of a rule in two queries: one aggregate scan and one sample (K8, K13). */
final class AudienceService
{
    private const SAMPLE_SIZE = 5;

    /** Customer columns that personalised text may use. Fixed here, never taken from input. */
    private const PERSONALIZATION_COLUMNS = ['first_name', 'last_name', 'city'];

    public function __construct(private readonly SegmentQueryCompiler $compiler) {}

    public function summarize(SegmentRule $rule): AudienceSummary
    {
        $totals = $this->compiler->compile($rule)->toBase()->selectRaw($this->aggregateSql())->first();

        $sample = $this->compiler->compile($rule)
            ->whereRaw($this->reachableSql())
            ->orderBy('id')
            ->limit(self::SAMPLE_SIZE)
            ->get(['id', 'first_name', 'last_name', 'city'])
            ->map(fn ($customer): array => [
                'id' => $customer->id,
                'first_name' => $customer->first_name,
                'last_name' => $customer->last_name,
                'city' => $customer->city,
            ])
            ->all();

        $counts = [];
        $longest = [];
        foreach (Channel::cases() as $channel) {
            $counts[$channel->value] = (int) $totals->{"{$channel->value}_count"};
            foreach (self::PERSONALIZATION_COLUMNS as $column) {
                $longest[$channel->value][$column] = (int) $totals->{"{$channel->value}_{$column}_longest"};
            }
        }

        return new AudienceSummary($counts, (int) $totals->unique_total, $sample, $longest);
    }

    private function aggregateSql(): string
    {
        $columns = [];
        foreach (Channel::cases() as $channel) {
            $consent = $this->compiler->consentSql($channel);
            $columns[] = "COUNT(*) FILTER (WHERE {$consent}) AS {$channel->value}_count";
            foreach (self::PERSONALIZATION_COLUMNS as $column) {
                $columns[] = "COALESCE(MAX(char_length({$column})) FILTER (WHERE {$consent}), 0) AS {$channel->value}_{$column}_longest";
            }
        }
        $columns[] = 'COUNT(*) FILTER (WHERE '.$this->reachableSql().') AS unique_total';

        return implode(', ', $columns);
    }

    /** Customers who may receive at least one channel. */
    private function reachableSql(): string
    {
        $conditions = array_map(fn (Channel $channel): string => $this->compiler->consentSql($channel), Channel::cases());

        return '('.implode(' OR ', $conditions).')';
    }
}
