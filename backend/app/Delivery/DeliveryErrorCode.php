<?php

declare(strict_types=1);

namespace App\Delivery;

/**
 * Why a delivery was retried, failed or skipped, as a small fixed set (K24). The dashboard turns the
 * code into a plain sentence for the marketer; the raw text in last_error stays for the engineers.
 */
enum DeliveryErrorCode: string
{
    case ConsentWithdrawn = 'consent_withdrawn';
    case ProviderTemporary = 'provider_temporary';
    case RetriesExhausted = 'retries_exhausted';
    case ProviderRejected = 'provider_rejected';
    case ContentInvalid = 'content_invalid';
    case Internal = 'internal';
}
