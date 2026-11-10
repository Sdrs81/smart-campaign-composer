<?php

declare(strict_types=1);

use App\Delivery\DeliveryErrorCode;
use App\Enums\DeliveryStatus;
use App\Exceptions\InvalidDeliveryTransition;
use App\Models\Delivery;
use Carbon\CarbonImmutable;

/**
 * The states each model method may start from. Everything else must be rejected.
 *
 * @return array<string, list<DeliveryStatus>>
 */
function deliveryMethodStarts(): array
{
    return [
        'markSent' => [DeliveryStatus::Sending],
        'markFailed' => [DeliveryStatus::Sending],
        'scheduleRetry' => [DeliveryStatus::Sending],
        'skip' => [DeliveryStatus::Pending, DeliveryStatus::Sending],
    ];
}

function callDeliveryMethod(Delivery $delivery, string $method): void
{
    match ($method) {
        'markSent' => $delivery->markSent('msg-1'),
        'markFailed' => $delivery->markFailed(DeliveryErrorCode::Internal, 'boom'),
        'scheduleRetry' => $delivery->scheduleRetry(CarbonImmutable::now()->addSecond(), DeliveryErrorCode::ProviderUnreachable, 'timeout'),
        'skip' => $delivery->skip(DeliveryErrorCode::ConsentWithdrawn, 'opted out'),
    };
}

dataset('rejected method and status', function () {
    foreach (deliveryMethodStarts() as $method => $starts) {
        foreach (DeliveryStatus::cases() as $status) {
            if (! in_array($status, $starts, true)) {
                yield "{$method} from {$status->value}" => [$method, $status];
            }
        }
    }
});

dataset('terminal status', [
    DeliveryStatus::Sent,
    DeliveryStatus::Failed,
    DeliveryStatus::Skipped,
]);

dataset('status pair', function () {
    $allowed = [
        'pending' => ['sending', 'skipped'],
        'sending' => ['pending', 'sent', 'failed', 'skipped'],
    ];

    foreach (DeliveryStatus::cases() as $from) {
        foreach (DeliveryStatus::cases() as $to) {
            yield "{$from->value} to {$to->value}" => [$from, $to, in_array($to->value, $allowed[$from->value] ?? [], true)];
        }
    }
});

it('rejects a method called from a state it may not start from', function (string $method, DeliveryStatus $from) {
    $delivery = (new Delivery)->forceFill(['status' => $from]);

    expect(fn () => callDeliveryMethod($delivery, $method))->toThrow(InvalidDeliveryTransition::class)
        ->and($delivery->status)->toBe($from)
        ->and($delivery->attempts)->toBe(0);
})->with('rejected method and status');

it('never leaves a terminal state', function (DeliveryStatus $terminal) {
    expect($terminal->isTerminal())->toBeTrue();

    foreach (array_keys(deliveryMethodStarts()) as $method) {
        $delivery = (new Delivery)->forceFill(['status' => $terminal]);

        expect(fn () => callDeliveryMethod($delivery, $method))->toThrow(InvalidDeliveryTransition::class);
    }
})->with('terminal status');

it('knows every allowed and forbidden status pair', function (DeliveryStatus $from, DeliveryStatus $to, bool $allowed) {
    expect($from->canTransitionTo($to))->toBe($allowed);
})->with('status pair');

it('treats only pending and sending as non-terminal', function () {
    expect(DeliveryStatus::Pending->isTerminal())->toBeFalse()
        ->and(DeliveryStatus::Sending->isTerminal())->toBeFalse();
});

it('names the states and the allowed next ones in the error', function () {
    $exception = InvalidDeliveryTransition::between(DeliveryStatus::Sent, DeliveryStatus::Pending);

    expect($exception->getMessage())->toBe("Delivery cannot move from 'sent' to 'pending'; allowed next states: none (final state).");
});

it('marks a failure with its error and counts the attempt', function () {
    $delivery = (new Delivery)->forceFill(['status' => DeliveryStatus::Sending]);

    $delivery->markFailed(DeliveryErrorCode::ProviderRejected, 'provider rejected the number');

    expect($delivery->status)->toBe(DeliveryStatus::Failed)
        ->and($delivery->last_error)->toBe('provider rejected the number')
        ->and($delivery->last_error_code)->toBe(DeliveryErrorCode::ProviderRejected)
        ->and($delivery->attempts)->toBe(1);
});

it('skips without counting an attempt', function () {
    $delivery = (new Delivery)->forceFill(['status' => DeliveryStatus::Pending]);

    $delivery->skip(DeliveryErrorCode::ConsentWithdrawn, 'sms opt-in withdrawn');

    expect($delivery->status)->toBe(DeliveryStatus::Skipped)
        ->and($delivery->last_error)->toBe('sms opt-in withdrawn')
        ->and($delivery->last_error_code)->toBe(DeliveryErrorCode::ConsentWithdrawn)
        ->and($delivery->attempts)->toBe(0);
});
