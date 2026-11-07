<?php

declare(strict_types=1);

namespace App\Content;

use App\Exceptions\CampaignContentUnavailable;
use App\Models\Campaign;
use App\Models\Customer;

/** Shows the saved text as one customer would receive it, with the visible length of each part. */
final class PreviewCampaignContent
{
    public function __construct(private readonly PersonalizationRenderer $renderer) {}

    /**
     * @param  Customer|null  $customer  null picks the campaign's first sample person, or nobody (all fallbacks)
     * @return array{customer: array<string, mixed>, messages: list<array<string, mixed>>}
     */
    public function __invoke(Campaign $campaign, ?Customer $customer): array
    {
        $messages = $campaign->messages()->orderBy('id')->get();
        if ($messages->isEmpty()) {
            throw CampaignContentUnavailable::notGenerated($campaign->status);
        }

        $person = $customer !== null
            ? $customer->only(['id', ...Variables::names()])
            : ($campaign->audience_counts['sample'][0] ?? []);
        $values = array_intersect_key($person, array_flip(Variables::names()));

        return [
            'customer' => ['id' => $person['id'] ?? null, ...$values],
            'messages' => $messages->map(function ($message) use ($values): array {
                $subject = $message->subject === null ? null : $this->renderer->render($message->subject, $values);
                $body = $this->renderer->render($message->body, $values);

                return [
                    'channel' => $message->channel,
                    'subject' => $subject,
                    'body' => $body,
                    'lengths' => [
                        'subject' => $subject === null ? null : $this->renderer->visibleLength($subject),
                        'body' => $this->renderer->visibleLength($body),
                    ],
                ];
            })->all(),
        ];
    }
}
