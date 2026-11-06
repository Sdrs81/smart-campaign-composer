<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\DeliveryStatus;
use LogicException;

/**
 * A programming error, not a client error: deliveries are only changed by the sending
 * pipeline, so this is deliberately not an ApiException.
 */
class InvalidDeliveryTransition extends LogicException
{
    public static function between(DeliveryStatus $from, DeliveryStatus $to): self
    {
        $allowed = array_map(fn (DeliveryStatus $status): string => $status->value, $from->allowedNext());

        return new self(sprintf(
            "Delivery cannot move from '%s' to '%s'; allowed next states: %s.",
            $from->value,
            $to->value,
            $allowed === [] ? 'none (final state)' : implode(', ', $allowed),
        ));
    }
}
