<?php

declare(strict_types=1);

namespace App\Delivery;

use App\Content\PersonalizationRenderer;
use App\Content\Variables;
use App\Enums\Channel;
use App\Enums\DeliveryStatus;
use App\Models\CampaignMessage;
use App\Models\Customer;
use App\Models\Delivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Sends one delivery. The attempt count lives in the database (K18), so Laravel's own attempt counter
 * is switched off: a retry is a release with a delay, and only unhandled exceptions are capped.
 */
class SendDeliveryJob implements ShouldQueue
{
    use Queueable;

    private const MILLISECONDS_PER_SECOND = 1000;

    /** 0 means unlimited; the retry policy decides when to stop. */
    public int $tries = 0;

    /** A bug that throws every time must not loop forever. The stale claim frees the row meanwhile. */
    public int $maxExceptions = 3;

    public function __construct(public readonly int $deliveryId) {}

    public function handle(MessageProvider $provider, RetryPolicy $retryPolicy, PersonalizationRenderer $renderer): void
    {
        $delivery = Delivery::query()->find($this->deliveryId);

        // A repeated or late job finds the row already final and does nothing (idempotent).
        if ($delivery === null || $delivery->status->isTerminal()) {
            return;
        }

        // K19 layer two: only one worker wins the row.
        if (! $this->claim($delivery)) {
            return;
        }
        $delivery->refresh();

        $customer = $delivery->customer;

        // Opt-in is checked again here because consent may have been withdrawn since the audience was frozen.
        if (! $customer->canReceive($delivery->channel)) {
            $delivery->skip("The customer can no longer receive {$delivery->channel->value} messages (consent withdrawn or no address).");
            $delivery->save();

            return;
        }

        $message = CampaignMessage::query()
            ->where('campaign_id', $delivery->campaign_id)
            ->where('channel', $delivery->channel->value)
            ->first();
        if ($message === null) {
            $delivery->markFailed("The campaign has no {$delivery->channel->value} message to send.");
            $delivery->save();

            return;
        }

        $payload = $this->renderPayload($message, $customer, $renderer);
        $lengthProblem = $this->lengthProblem($delivery->channel, $payload, $renderer);
        if ($lengthProblem !== null) {
            $delivery->markFailed($lengthProblem);
            $delivery->save();

            return;
        }

        $result = $provider->send($delivery->channel, $payload, $delivery->idempotency_key);

        $this->recordResult($delivery, $result, $retryPolicy);
    }

    /** One conditional UPDATE decides the winner: a pending row, or a "sending" row whose worker died. */
    private function claim(Delivery $delivery): bool
    {
        $claimed = Delivery::query()
            ->whereKey($delivery->getKey())
            ->where(fn ($query) => $query
                ->where('status', DeliveryStatus::Pending->value)
                ->orWhere(fn ($stale) => $stale
                    ->where('status', DeliveryStatus::Sending->value)
                    ->whereRaw("claimed_at < now() - ?::int * interval '1 second'", [config('delivery.claim_timeout_seconds')])))
            ->update(['status' => DeliveryStatus::Sending->value, 'claimed_at' => DB::raw('now()')]);

        return $claimed === 1;
    }

    /** @return array<string, string> "to" and "body", plus "subject" for email */
    private function renderPayload(CampaignMessage $message, Customer $customer, PersonalizationRenderer $renderer): array
    {
        $values = [];
        foreach (Variables::names() as $name) {
            $value = $customer->getAttribute($name);
            $values[$name] = is_string($value) ? $value : null;
        }

        $payload = [
            'to' => (string) ($message->channel === Channel::Sms ? $customer->phone : $customer->email),
            'body' => $renderer->render($message->body, $values),
        ];

        if ($message->channel === Channel::Email) {
            $payload['subject'] = $renderer->render((string) $message->subject, $values);
        }

        return $payload;
    }

    /**
     * The audience was validated against the worst case, but this customer's real text is what goes out,
     * so it is measured once more against the same limits.
     *
     * @param  array<string, string>  $payload
     */
    private function lengthProblem(Channel $channel, array $payload, PersonalizationRenderer $renderer): ?string
    {
        $limits = match ($channel) {
            Channel::Sms => ['body' => config('delivery.limits.sms_body_max')],
            Channel::Email => ['subject' => config('delivery.limits.email_subject_max'), 'body' => config('delivery.limits.email_body_max')],
        };

        foreach ($limits as $field => $max) {
            $length = $renderer->visibleLength($payload[$field]);
            if ($length > $max) {
                return "The rendered {$channel->value} {$field} is {$length} characters; the limit is {$max}.";
            }
        }

        return null;
    }

    private function recordResult(Delivery $delivery, SendResult $result, RetryPolicy $retryPolicy): void
    {
        if ($result->outcome === SendOutcome::Sent) {
            $delivery->markSent((string) $result->providerMessageId);
            $delivery->save();

            return;
        }

        // attempts counts the finished ones, so this failed attempt is one more.
        $decision = $retryPolicy->decide($result, $delivery->attempts + 1);

        if (! $decision->shouldRetry()) {
            $delivery->markFailed((string) $decision->reason);
            $delivery->save();

            return;
        }

        $delay = (int) $decision->delayMilliseconds;
        $delivery->scheduleRetry(now()->addMilliseconds($delay), (string) $result->reason);
        $delivery->save();

        $this->release((int) ceil($delay / self::MILLISECONDS_PER_SECOND));
    }
}
