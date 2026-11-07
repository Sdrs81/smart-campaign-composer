<?php

declare(strict_types=1);

namespace App\Content;

use App\Enums\MessageSource;
use App\Llm\ContentEvent;
use App\Llm\ContentGenerator;
use App\Models\Campaign;
use App\Segment\AudienceSummary;
use Generator;
use Illuminate\Support\Facades\DB;

/**
 * Streams the generated text of a campaign and, once it is validated, replaces the campaign's messages
 * with it. The messages are saved before the `final` event goes out, so a client that sees `final`
 * can always read them back.
 */
final class GenerateCampaignContent
{
    public function __construct(private readonly ContentGenerator $generator) {}

    /** @return Generator<int, ContentEvent> */
    public function __invoke(Campaign $campaign, string $tone): Generator
    {
        $audience = AudienceSummary::fromArray($campaign->audience_counts ?? []);
        // The rule is stored as data, not prose; the model reads it as the description of who was chosen.
        $ruleExplanation = json_encode(
            ['rule' => $campaign->segment_rule, 'not_expressed' => $campaign->unsupported_criteria],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        );

        foreach ($this->generator->generate($campaign->brief, $tone, $audience, $ruleExplanation) as $event) {
            if ($event->type === ContentEvent::COMPLETED) {
                $this->store($campaign, $event);
            }

            yield $event;
        }
    }

    private function store(Campaign $campaign, ContentEvent $final): void
    {
        DB::transaction(function () use ($campaign, $final): void {
            $locked = Campaign::query()->lockForUpdate()->findOrFail($campaign->id);
            // Throws when the campaign was approved while the model was writing.
            $locked->setContent();

            foreach ([$final->sms, $final->email] as $message) {
                $locked->messages()->updateOrCreate(
                    ['channel' => $message->channel->value],
                    ['subject' => $message->subject, 'body' => $message->body, 'source' => MessageSource::Llm],
                );
            }

            $locked->save();
        });
    }
}
