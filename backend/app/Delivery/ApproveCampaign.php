<?php

declare(strict_types=1);

namespace App\Delivery;

use App\Content\ContentValidator;
use App\Content\MessageContent;
use App\Enums\Channel;
use App\Exceptions\CampaignApprovalRefused;
use App\Models\Campaign;
use App\Segment\AudienceSummary;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * A person's approval (K15). The campaign lock makes two clicks at once into one approval, and a repeat
 * after approval returns the current state without starting a second send.
 */
final class ApproveCampaign
{
    public function __construct(private readonly ContentValidator $validator) {}

    public function __invoke(Campaign $campaign): Campaign
    {
        $lock = Cache::lock("campaign:{$campaign->id}:approve", (int) config('delivery.approval_lock_seconds'));

        try {
            return $lock->block((int) config('delivery.approval_lock_wait_seconds'), fn (): Campaign => $this->approve($campaign->id));
        } catch (LockTimeoutException) {
            throw CampaignApprovalRefused::inProgress($campaign->id);
        }
    }

    private function approve(int $campaignId): Campaign
    {
        // Read again under the lock: another request may have approved while this one waited.
        $campaign = Campaign::query()->with('messages')->findOrFail($campaignId);

        if ($campaign->status->isApprovedOrLater()) {
            return $campaign;
        }

        // Refuses any state but content_ready (409) before the other checks.
        $campaign->approve();

        $audience = AudienceSummary::fromArray($campaign->audience_counts ?? []);
        if ($audience->uniqueTotal === 0) {
            throw CampaignApprovalRefused::emptyAudience($campaign->id);
        }

        $this->assertContentValid($campaign, $audience);

        $campaign->save();
        StartCampaignJob::dispatch($campaign->id);

        return $campaign;
    }

    /** The text is checked once more against the audience it will go to, with the same validator as every edit (K13). */
    private function assertContentValid(Campaign $campaign, AudienceSummary $audience): void
    {
        $problems = [];

        foreach (Channel::cases() as $channel) {
            $message = $campaign->messages->firstWhere('channel', $channel);
            if ($message === null) {
                $problems["{$channel->value}.body"][] = "the {$channel->value} message is missing.";

                continue;
            }

            $content = new MessageContent($channel, $message->subject, $message->body);
            foreach ($this->validator->validate($content, $audience->longestValues[$channel->value] ?? [])->errors ?? [] as $error) {
                $problems[$error['path']][] = $error['message'];
            }
        }

        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }
    }
}
