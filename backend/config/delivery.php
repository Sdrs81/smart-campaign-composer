<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Content limits
    |--------------------------------------------------------------------------
    |
    | Single source of truth for message limits. LLM output and human edits go
    | through the same validator, and the frontend reads these through the
    | rules endpoint instead of keeping its own copy. Counts are in visible
    | characters, measured on the worst-case rendered text.
    |
    */

    'limits' => [
        'sms_body_max' => 160,
        'email_subject_max' => 80,
        'email_body_max' => 2000,
    ],

    'channels' => ['sms', 'email'],

    /*
    |--------------------------------------------------------------------------
    | Sending
    |--------------------------------------------------------------------------
    */

    // Global outbound limit per channel, shared by all workers (K17).
    'rate_per_second' => (int) env('SEND_RATE_PER_SECOND', 100),

    // Kept well under claim_timeout_seconds: a worker waits holding a claim, and a longer wait would let
    // another worker take the row over.
    'rate_limit_max_wait_seconds' => 30,

    'workers_per_channel' => (int) env('WORKERS_PER_CHANNEL', 8),

    // A delivery stuck in "sending" longer than this is taken over by another worker (K19 layer two).
    'claim_timeout_seconds' => 60,

    'provider' => [
        'url' => env('PROVIDER_URL', 'http://mock-provider:8080'),
        'connect_timeout' => 1,
        'timeout' => 3,
    ],

    // Retry policy (K18): exponential backoff with full jitter, 429 honours Retry-After.
    'retry' => [
        'max_attempts' => 5,
        'base_delay_ms' => 200,
        'max_delay_ms' => 10000,
    ],

];
