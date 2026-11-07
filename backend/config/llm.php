<?php

declare(strict_types=1);

$requested = (string) env('LLM_DRIVER', 'auto');
$apiKey = env('ANTHROPIC_API_KEY');

return [

    /*
    |--------------------------------------------------------------------------
    | LLM driver
    |--------------------------------------------------------------------------
    |
    | "requested" is the raw LLM_DRIVER value (auto|claude|fake). "driver" is
    | the resolved value the container binds: with "auto", a missing
    | ANTHROPIC_API_KEY selects the offline fake client (K9).
    |
    */

    'requested' => $requested,

    'driver' => $requested === 'auto'
        ? (filled($apiKey) ? 'claude' : 'fake')
        : $requested,

    'anthropic' => [
        'api_key' => $apiKey,
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5-5'),
        'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 2048),
        'timeout' => (int) env('ANTHROPIC_TIMEOUT', 60),
    ],

    // With the fake driver, a call that finds the scenario queue empty is answered from resources/llm-fixtures,
    // so the app runs end to end without a key (5.11). Tests switch this off to keep their scenarios exact.
    'fake' => [
        'dev_scenarios' => (bool) env('LLM_FAKE_DEV_SCENARIOS', true),
    ],

    // Validation failures are fed back to the model at most this many times (K11).
    'max_repair_attempts' => 2,

    // Marketer brief accepted by the segment extractor, in characters.
    'brief' => [
        'min_length' => 10,
        'max_length' => 1000,
    ],

    // The answer is short JSON, but thinking tokens count against max_tokens, so leave room for them.
    'segment' => [
        'max_tokens' => 4096,
    ],

    // An SMS plus an email of up to 2000 characters in Turkish, with room for thinking tokens.
    'content' => [
        'max_tokens' => 8192,
        // Used when the marketer does not name a tone; the tone limit keeps it a style hint, not a second brief.
        'default_tone' => 'samimi ve profesyonel',
        'tone_max_length' => 100,
    ],

];
