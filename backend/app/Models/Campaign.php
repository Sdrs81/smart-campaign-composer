<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CampaignStatus;
use App\Enums\DeliveryStatus;
use App\Exceptions\InvalidCampaignTransition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $brief
 * @property CampaignStatus $status
 * @property array<string, mixed>|null $segment_rule
 * @property list<mixed>|null $unsupported_criteria
 * @property array<string, mixed>|null $audience_counts
 */
class Campaign extends Model
{
    // Only the brief is mass assignable: status moves exclusively through the methods below.
    /** @var list<string> */
    protected $fillable = ['brief'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'draft'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CampaignStatus::class,
            'segment_rule' => 'array',
            'unsupported_criteria' => 'array',
            'audience_counts' => 'array',
        ];
    }

    /**
     * @return HasMany<CampaignMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(CampaignMessage::class);
    }

    /**
     * The caller persists the model after a transition; these methods only change it in memory.
     *
     * @param  array<string, mixed>  $segmentRule
     * @param  list<mixed>  $unsupportedCriteria
     * @param  array<string, mixed>  $audienceCounts
     */
    public function defineAudience(array $segmentRule, array $unsupportedCriteria, array $audienceCounts): void
    {
        $this->transitionTo(CampaignStatus::AudienceReady);
        $this->segment_rule = $segmentRule;
        $this->unsupported_criteria = $unsupportedCriteria;
        $this->audience_counts = $audienceCounts;
    }

    public function setContent(): void
    {
        $this->transitionTo(CampaignStatus::ContentReady);
    }

    public function approve(): void
    {
        $this->transitionTo(CampaignStatus::Approved);
    }

    public function startSending(): void
    {
        $this->transitionTo(CampaignStatus::Sending);
    }

    public function complete(): void
    {
        $this->transitionTo(CampaignStatus::Completed);
    }

    /**
     * Completes a sending campaign when no delivery is left pending or sending. One conditional UPDATE,
     * so concurrent workers cannot complete it twice or too early: each caller has already saved its own
     * delivery, so whoever finishes last sees all of them final. Unlike the methods above it writes at once,
     * because the check and the change must be one statement. Sending to completed is the transition
     * CampaignStatus allows.
     *
     * @return bool true when this call completed the campaign
     */
    public static function completeIfFinished(int $campaignId): bool
    {
        return self::query()
            ->whereKey($campaignId)
            ->where('status', CampaignStatus::Sending->value)
            ->whereNotExists(fn ($unfinished) => $unfinished
                ->from('deliveries')
                ->selectRaw('1')
                ->whereColumn('deliveries.campaign_id', 'campaigns.id')
                ->whereIn('deliveries.status', [DeliveryStatus::Pending->value, DeliveryStatus::Sending->value]))
            ->update(['status' => CampaignStatus::Completed->value]) === 1;
    }

    public function fail(): void
    {
        $this->transitionTo(CampaignStatus::Failed);
    }

    /** Lets a caller refuse before doing expensive work (an LLM call) that defineAudience() would reject afterwards. */
    public function assertAudienceCanBeDefined(): void
    {
        $this->assertCanTransitionTo(CampaignStatus::AudienceReady);
    }

    /** Content needs a counted audience, so a draft is refused here before any stream is opened. */
    public function assertContentCanBeSet(): void
    {
        $this->assertCanTransitionTo(CampaignStatus::ContentReady);
    }

    private function transitionTo(CampaignStatus $next): void
    {
        $this->assertCanTransitionTo($next);

        $this->status = $next;
    }

    private function assertCanTransitionTo(CampaignStatus $next): void
    {
        if (! $this->status->canTransitionTo($next)) {
            throw InvalidCampaignTransition::between($this->status, $next);
        }
    }
}
