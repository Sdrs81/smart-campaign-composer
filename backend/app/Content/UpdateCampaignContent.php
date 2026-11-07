<?php

declare(strict_types=1);

namespace App\Content;

use App\Enums\CampaignStatus;
use App\Enums\MessageSource;
use App\Exceptions\CampaignContentUnavailable;
use App\Models\Campaign;
use App\Segment\AudienceSummary;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A person's edit of the generated text. It goes through the same ContentValidator as model output (K13),
 * and is only possible while the campaign waits for approval.
 */
final class UpdateCampaignContent
{
    public function __construct(private readonly ContentValidator $validator) {}

    public function __invoke(Campaign $campaign, MessageContent $sms, MessageContent $email): Campaign
    {
        $this->assertEditable($campaign);

        $longestValues = AudienceSummary::fromArray($campaign->audience_counts ?? [])->longestValues;
        $problems = [];
        foreach ([$sms, $email] as $content) {
            foreach ($this->validator->validate($content, $longestValues[$content->channel->value] ?? [])->errors ?? [] as $error) {
                $problems[$error['path']][] = $error['message'];
            }
        }
        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }

        return DB::transaction(function () use ($campaign, $sms, $email): Campaign {
            $locked = Campaign::query()->lockForUpdate()->findOrFail($campaign->id);
            // Approval may have happened since the request was read.
            $this->assertEditable($locked);

            foreach ([$sms, $email] as $content) {
                $message = $locked->messages()->firstOrNew(['channel' => $content->channel->value]);
                $message->fill(['subject' => $content->subject, 'body' => $content->body]);
                // Text the person left alone stays attributed to the model.
                if ($message->isDirty(['subject', 'body'])) {
                    $message->source = MessageSource::Human;
                }
                $message->save();
            }

            return $locked->load('messages');
        });
    }

    private function assertEditable(Campaign $campaign): void
    {
        if ($campaign->status !== CampaignStatus::ContentReady) {
            throw CampaignContentUnavailable::notEditable($campaign->status);
        }
    }
}
