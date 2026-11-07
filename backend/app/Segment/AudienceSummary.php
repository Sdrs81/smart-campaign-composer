<?php

declare(strict_types=1);

namespace App\Segment;

/** What the marketer sees before approving: who can be reached, and how long the personalised text can get. */
final readonly class AudienceSummary
{
    /**
     * @param  array<string, int>  $channelCounts  customers who may receive the channel, keyed by channel value
     * @param  int  $uniqueTotal  customers reachable on at least one channel, counted once
     * @param  list<array{id: int, first_name: string|null, last_name: string, city: string}>  $sample
     * @param  array<string, array<string, int>>  $longestValues  channel => variable => longest value in characters (K13)
     */
    public function __construct(
        public array $channelCounts,
        public int $uniqueTotal,
        public array $sample,
        public array $longestValues,
    ) {}

    /**
     * The form kept in campaigns.audience_counts.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'channel_counts' => $this->channelCounts,
            'unique_total' => $this->uniqueTotal,
            'sample' => $this->sample,
            'longest_values' => $this->longestValues,
        ];
    }

    /** @param  array<string, mixed>  $stored  the output of toArray(), read back from the database */
    public static function fromArray(array $stored): self
    {
        return new self($stored['channel_counts'], $stored['unique_total'], $stored['sample'], $stored['longest_values']);
    }
}
