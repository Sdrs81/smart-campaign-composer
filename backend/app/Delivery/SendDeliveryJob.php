<?php

declare(strict_types=1);

namespace App\Delivery;

use App\Content\PersonalizationRenderer;
use App\Content\Variables;
use App\Enums\Channel;
use App\Enums\DeliveryStatus;
use App\Models\Campaign;
use App\Models\CampaignMessage;
use App\Models\Customer;
use App\Models\Delivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends one delivery. The attempt count lives in the database (K18), so Laravel's own attempt counter
 * is switched off: a retry is a release with a delay, and only unhandled exceptions are capped.
 */
class SendDeliveryJob implements ShouldQueue
{
    use Queueable;

    private const MILLISECONDS_PER_SECOND = 1000;

    private const SATURATED_CHANNEL_RETRY_SECONDS = 1;

    /** 0 means unlimited; the retry policy decides when to stop. */
    public int $tries = 0;

    /** A bug that throws every time must not loop forever. The stale claim frees the row meanwhile. */
    public int $maxExceptions = 3;

    public function __construct(public readonly int $deliveryId) {}

    /**
     * After an unexpected exception the row is still "sending", and claim() only takes it once the claim is
     * stale. Retrying sooner would find it held and drop the job, leaving the delivery stuck.
     */
    public function backoff(): int
    {
        return (int) config('delivery.claim_timeout_seconds') + 1;
    }

    /** Runs when the job gives up after repeated exceptions, so the delivery ends as failed instead of stuck. */
    public function failed(Throwable $exception): void
    {
        $delivery = Delivery::query()->find($this->deliveryId);
        $claimIsStale = $delivery?->claimed_at?->lt(now()->subSeconds((int) config('delivery.claim_timeout_seconds'))) === true;

        // A fresh claim belongs to another worker that is still sending.
        if ($delivery === null || $delivery->status !== DeliveryStatus::Sending || ! $claimIsStale) {
            return;
        }

        $delivery->markFailed("The send job failed after repeated errors: {$exception->getMessage()}");
        $this->saveAndLog($delivery);
        Campaign::completeIfFinished($delivery->campaign_id);
    }

    public function handle(
        MessageProvider $provider,
        RetryPolicy $retryPolicy,
        PersonalizationRenderer $renderer,
        ChannelRateLimiter $rateLimiter,
    ): void {
        $delivery = Delivery::query()->find($this->deliveryId);

        if ($delivery === null) {
            return;
        }

        // A repeated or late job finds the row already final and sends nothing (idempotent).
        if (! $delivery->status->isTerminal()) {
            // K19 layer two: only one worker wins the row.
            if (! $this->claim($delivery)) {
                return;
            }
            $delivery->refresh();

            $this->deliver($delivery, $provider, $retryPolicy, $renderer, $rateLimiter);
        }

        // Checked even for a row that was already final: a worker that died between saving the last
        // delivery and completing the campaign is repaired by the repeated job.
        if ($delivery->status->isTerminal()) {
            Campaign::completeIfFinished($delivery->campaign_id);
        }
    }

    private function deliver(
        Delivery $delivery,
        MessageProvider $provider,
        RetryPolicy $retryPolicy,
        PersonalizationRenderer $renderer,
        ChannelRateLimiter $rateLimiter,
    ): void {
        $customer = $delivery->customer;

        // Opt-in is checked again here because consent may have been withdrawn since the audience was frozen.
        if (! $customer->canReceive($delivery->channel)) {
            $delivery->skip("The customer can no longer receive {$delivery->channel->value} messages (consent withdrawn or no address).");
            $this->saveAndLog($delivery);

            return;
        }

        $message = CampaignMessage::query()
            ->where('campaign_id', $delivery->campaign_id)
            ->where('channel', $delivery->channel->value)
            ->first();
        if ($message === null) {
            $delivery->markFailed("The campaign has no {$delivery->channel->value} message to send.");
            $this->saveAndLog($delivery);

            return;
        }

        $payload = $this->renderPayload($message, $customer, $renderer);
        $lengthProblem = $this->lengthProblem($delivery->channel, $payload, $renderer);
        if ($lengthProblem !== null) {
            $delivery->markFailed($lengthProblem);
            $this->saveAndLog($delivery);

            return;
        }

        // Waiting here, not sending into a 429, avoids most refusals and the retry attempts they would use up.
        try {
            $rateLimiter->await($delivery->channel);
        } catch (ChannelRateLimitTimeout) {
            // Nothing was sent, so this is not an attempt: hand the row back and look again shortly.
            $this->releaseClaim($delivery);
            $this->release(self::SATURATED_CHANNEL_RETRY_SECONDS);

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

    /** The counterpart of claim(): sending back to pending, so the job released for later can claim it again. */
    private function releaseClaim(Delivery $delivery): void
    {
        Delivery::query()
            ->whereKey($delivery->getKey())
            ->where('status', DeliveryStatus::Sending->value)
            ->update(['status' => DeliveryStatus::Pending->value, 'claimed_at' => null]);
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

    /**
     * Every outcome is saved and logged in one place. The line carries ids and the reason only: the
     * recipient and the rendered text stay out, and the formatter masks anything that slips in.
     */
    private function saveAndLog(Delivery $delivery): void
    {
        $delivery->save();

        // A delivery back in "pending" after a send is a scheduled retry, not a new delivery.
        $event = $delivery->status === DeliveryStatus::Pending ? 'retry_scheduled' : $delivery->status->value;

        Log::log($delivery->status === DeliveryStatus::Failed ? 'warning' : 'info', "delivery.{$event}", [
            'campaign_id' => $delivery->campaign_id,
            'delivery_id' => $delivery->id,
            'attempt' => $delivery->attempts,
            'channel' => $delivery->channel->value,
            'reason' => $delivery->status === DeliveryStatus::Sent ? null : $delivery->last_error,
        ]);
    }

    private function recordResult(Delivery $delivery, SendResult $result, RetryPolicy $retryPolicy): void
    {
        if ($result->outcome === SendOutcome::Sent) {
            $delivery->markSent((string) $result->providerMessageId);
            $this->saveAndLog($delivery);

            return;
        }

        // attempts counts the finished ones, so this failed attempt is one more.
        $decision = $retryPolicy->decide($result, $delivery->attempts + 1);

        if (! $decision->shouldRetry()) {
            $delivery->markFailed((string) $decision->reason);
            $this->saveAndLog($delivery);

            return;
        }

        $delay = (int) $decision->delayMilliseconds;
        $delivery->scheduleRetry(now()->addMilliseconds($delay), (string) $result->reason);
        $this->saveAndLog($delivery);

        $this->release((int) ceil($delay / self::MILLISECONDS_PER_SECOND));
    }
}
