<?php

declare(strict_types=1);

namespace App\Delivery;

use App\Enums\Channel;
use App\Models\Campaign;
use App\Models\Delivery;
use App\Segment\SegmentQueryCompiler;
use App\Segment\SegmentRule;
use App\Segment\SegmentRuleParser;
use App\Segment\ValidationErrors;
use LogicException;

/**
 * Freezes the audience into deliveries at approval (K16). One INSERT … SELECT per channel runs inside
 * Postgres, so no customer row travels through PHP, and ON CONFLICT DO NOTHING makes a repeat harmless (K19).
 */
final class AudienceSnapshotter
{
    public function __construct(
        private readonly SegmentRuleParser $parser,
        private readonly SegmentQueryCompiler $compiler,
    ) {}

    /** @return int deliveries created by this call; rows that already existed are not counted */
    public function freeze(Campaign $campaign): int
    {
        $rule = $this->storedRule($campaign);

        $created = 0;
        foreach (Channel::cases() as $channel) {
            $created += $this->freezeChannel($campaign, $rule, $channel);
        }

        return $created;
    }

    private function freezeChannel(Campaign $campaign, SegmentRule $rule, Channel $channel): int
    {
        // Opt-in is checked again here, at the moment of freezing, not only when the audience was counted (K8).
        $customers = $this->compiler->withChannelConsent($this->compiler->compile($rule), $channel)->toBase();

        // The key is sha256 of "campaign:customer:channel", computed in the database like the rest of the row.
        $customers->selectRaw(
            "?::bigint AS campaign_id, id AS customer_id, ?::text AS channel, encode(sha256(convert_to(?::text || ':' || id::text || ':' || ?::text, 'UTF8')), 'hex') AS idempotency_key",
            [$campaign->id, $channel->value, $campaign->id, $channel->value],
        );

        return Delivery::query()->insertOrIgnoreUsing(['campaign_id', 'customer_id', 'channel', 'idempotency_key'], $customers);
    }

    private function storedRule(Campaign $campaign): SegmentRule
    {
        $rule = $this->parser->parse($campaign->segment_rule ?? []);

        // The rule was validated when it was stored; failing here means the stored data changed under us.
        if ($rule instanceof ValidationErrors) {
            throw new LogicException("Campaign {$campaign->id} has a stored segment rule that no longer parses: ".json_encode($rule->errors));
        }

        return $rule;
    }
}
