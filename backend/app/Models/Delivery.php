<?php

declare(strict_types=1);

namespace App\Models;

use App\Delivery\DeliveryErrorCode;
use App\Enums\Channel;
use App\Enums\DeliveryStatus;
use App\Exceptions\InvalidDeliveryTransition;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message to one customer on one channel. `attempts` counts finished provider attempts:
 * it grows on sent, retry and failed, never on skipped.
 *
 * @property int $campaign_id
 * @property int $customer_id
 * @property Channel $channel
 * @property DeliveryStatus $status
 * @property int $attempts
 * @property CarbonInterface|null $next_attempt_at
 * @property CarbonInterface|null $claimed_at
 * @property string|null $last_error
 * @property DeliveryErrorCode|null $last_error_code
 * @property string $idempotency_key
 * @property string|null $provider_message_id
 * @property CarbonInterface|null $sent_at
 */
class Delivery extends Model
{
    // Status and outcome columns change only through the methods below (or the atomic claim UPDATE).
    /** @var list<string> */
    protected $fillable = ['campaign_id', 'customer_id', 'channel', 'idempotency_key'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'pending', 'attempts' => 0];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'status' => DeliveryStatus::class,
            'attempts' => 'integer',
            'last_error_code' => DeliveryErrorCode::class,
            'next_attempt_at' => 'datetime',
            'claimed_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** The caller persists the model after a transition; these methods only change it in memory. */
    public function markSent(string $providerMessageId): void
    {
        $this->transitionTo(DeliveryStatus::Sent);
        $this->provider_message_id = $providerMessageId;
        $this->sent_at = now();
        $this->attempts++;
    }

    public function markFailed(DeliveryErrorCode $code, string $error): void
    {
        $this->transitionTo(DeliveryStatus::Failed);
        $this->recordError($code, $error);
        $this->attempts++;
    }

    /** Releases the claim so the delivery is picked up again at $retryAt. */
    public function scheduleRetry(CarbonInterface $retryAt, DeliveryErrorCode $code, string $error): void
    {
        $this->transitionTo(DeliveryStatus::Pending);
        $this->next_attempt_at = $retryAt;
        $this->claimed_at = null;
        $this->recordError($code, $error);
        $this->attempts++;
    }

    /** No provider call happened (for example consent was withdrawn); the reason goes to last_error. */
    public function skip(DeliveryErrorCode $code, string $reason): void
    {
        $this->transitionTo(DeliveryStatus::Skipped);
        $this->recordError($code, $reason);
    }

    private function recordError(DeliveryErrorCode $code, string $error): void
    {
        $this->last_error_code = $code;
        $this->last_error = $error;
    }

    private function transitionTo(DeliveryStatus $next): void
    {
        if (! $this->status->canTransitionTo($next)) {
            throw InvalidDeliveryTransition::between($this->status, $next);
        }

        $this->status = $next;
    }
}
