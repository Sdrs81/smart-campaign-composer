<?php

declare(strict_types=1);

namespace App\Segment;

use App\Llm\SegmentExtractor;
use App\Models\Campaign;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Brief → rule → opt-in counts, stored on the campaign. Safe to repeat until approval: the rule is never
 * edited by hand, so changing the brief and running this again is the only way to change the audience.
 */
final class DefineCampaignAudience
{
    public function __construct(
        private readonly SegmentExtractor $extractor,
        private readonly AudienceService $audience,
    ) {}

    /**
     * @param  string|null  $brief  an edited brief to extract from; it is saved with the audience, and only when extraction succeeds, so the stored brief always matches the stored rule
     */
    public function __invoke(Campaign $campaign, ?string $brief = null): Campaign
    {
        // Refuse before the paid model call, not after it.
        $campaign->assertAudienceCanBeDefined();

        $extraction = $this->extractor->extract($brief ?? $campaign->brief, Carbon::today());
        $summary = $this->audience->summarize($extraction->rule);

        // The model call took seconds; re-read under a lock so an approval made meanwhile is not overwritten.
        return DB::transaction(function () use ($campaign, $brief, $extraction, $summary): Campaign {
            $locked = Campaign::query()->lockForUpdate()->findOrFail($campaign->id);
            $locked->defineAudience($extraction->rule->toArray(), $extraction->unsupported, $summary->toArray());
            if ($brief !== null) {
                $locked->brief = $brief;
            }
            $locked->save();
            // The text was checked against the old audience's longest values, so it cannot stay as if still valid.
            $locked->messages()->delete();

            return $locked;
        });
    }
}
