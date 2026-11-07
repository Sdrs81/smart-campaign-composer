<?php

declare(strict_types=1);

use App\Llm\ClaudeLlmClient;
use App\Llm\Exceptions\LlmBadResponse;
use App\Llm\Exceptions\LlmRefused;
use App\Llm\Exceptions\LlmTruncated;
use App\Llm\Exceptions\LlmUnavailable;
use App\Llm\LlmRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

const CLAUDE_TEST_KEY = 'sk-ant-test-secret-key';

function claudeClient(): ClaudeLlmClient
{
    return new ClaudeLlmClient(CLAUDE_TEST_KEY, 'claude-sonnet-5-5', 30);
}

/** @param  array<string, mixed>  $overrides */
function claudeBody(array $overrides = []): array
{
    return array_merge([
        'content' => [['type' => 'text', 'text' => '{"ok":true}']],
        'stop_reason' => 'end_turn',
        'usage' => ['input_tokens' => 12, 'output_tokens' => 7],
    ], $overrides);
}

function claudeRequest(?array $schema = null): LlmRequest
{
    return new LlmRequest('system prompt', [['role' => 'user', 'content' => 'brief']], 512, $schema);
}

beforeEach(fn () => Sleep::fake());

it('returns text, usage and stop reason on success', function (): void {
    Http::fake([ClaudeLlmClient::API_URL => Http::response(claudeBody())]);

    $response = claudeClient()->complete(claudeRequest());

    expect($response->text)->toBe('{"ok":true}')
        ->and($response->usage->inputTokens)->toBe(12)
        ->and($response->usage->outputTokens)->toBe(7)
        ->and($response->stopReason)->toBe('end_turn');
});

it('sends the model, limits, schema and auth headers in the request', function (): void {
    Http::fake([ClaudeLlmClient::API_URL => Http::response(claudeBody())]);
    $schema = ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']], 'additionalProperties' => false];

    claudeClient()->complete(claudeRequest($schema));

    Http::assertSent(fn (Request $request): bool => $request->url() === ClaudeLlmClient::API_URL
        && $request['model'] === 'claude-sonnet-5-5'
        && $request['max_tokens'] === 512
        && $request['system'] === 'system prompt'
        && $request['messages'] === [['role' => 'user', 'content' => 'brief']]
        && $request['output_config'] === ['format' => ['type' => 'json_schema', 'schema' => $schema]]
        && $request->header('x-api-key') === [CLAUDE_TEST_KEY]
        && $request->header('anthropic-version') === [ClaudeLlmClient::API_VERSION]);
});

it('omits output_config when no schema is given', function (): void {
    Http::fake([ClaudeLlmClient::API_URL => Http::response(claudeBody())]);

    claudeClient()->complete(claudeRequest());

    Http::assertSent(fn (Request $request): bool => ! isset($request['output_config']));
});

it('retries after 429 using retry-after, then succeeds', function (): void {
    Http::fake([ClaudeLlmClient::API_URL => Http::sequence()
        ->push(['error' => ['type' => 'rate_limit_error', 'message' => 'slow down']], 429, ['retry-after' => '4'])
        ->push(claudeBody()),
    ]);

    expect(claudeClient()->complete(claudeRequest())->text)->toBe('{"ok":true}');

    Http::assertSentCount(2);
    Sleep::assertSequence([Sleep::for(4)->seconds()]);
});

it('gives up with LlmUnavailable after three 529 responses', function (): void {
    Http::fake([ClaudeLlmClient::API_URL => Http::response(['error' => ['type' => 'overloaded_error']], 529)]);

    expect(fn () => claudeClient()->complete(claudeRequest()))
        ->toThrow(LlmUnavailable::class, 'status 529');

    Http::assertSentCount(ClaudeLlmClient::MAX_ATTEMPTS);
    Sleep::assertSleptTimes(ClaudeLlmClient::MAX_ATTEMPTS - 1);
});

it('treats connection errors as retryable', function (): void {
    Http::fake([ClaudeLlmClient::API_URL => Http::sequence()
        ->pushFailedConnection('timed out')
        ->push(claudeBody()),
    ]);

    expect(claudeClient()->complete(claudeRequest())->text)->toBe('{"ok":true}');
});

it('throws LlmTruncated on max_tokens', function (): void {
    Http::fake([ClaudeLlmClient::API_URL => Http::response(claudeBody(['stop_reason' => 'max_tokens']))]);

    expect(fn () => claudeClient()->complete(claudeRequest()))->toThrow(LlmTruncated::class);
});

it('throws LlmRefused with the category on refusal', function (): void {
    Http::fake([ClaudeLlmClient::API_URL => Http::response(claudeBody([
        'stop_reason' => 'refusal',
        'stop_details' => ['type' => 'refusal', 'category' => 'general_harms'],
    ]))]);

    expect(fn () => claudeClient()->complete(claudeRequest()))->toThrow(LlmRefused::class, 'general_harms');
});

it('does not retry a 400 and reports the API message', function (): void {
    Http::fake([ClaudeLlmClient::API_URL => Http::response(
        ['error' => ['type' => 'invalid_request_error', 'message' => 'bad schema']],
        400,
    )]);

    expect(fn () => claudeClient()->complete(claudeRequest()))
        ->toThrow(LlmBadResponse::class, 'bad schema');

    Http::assertSentCount(1);
});

it('throws LlmBadResponse when the body has no content array', function (): void {
    Http::fake([ClaudeLlmClient::API_URL => Http::response(['stop_reason' => 'end_turn'])]);

    expect(fn () => claudeClient()->complete(claudeRequest()))->toThrow(LlmBadResponse::class, 'content');
});

it('never writes the api key to the log or an exception message', function (): void {
    Http::fake([ClaudeLlmClient::API_URL => Http::response(['error' => ['type' => 'overloaded_error']], 529)]);
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message.json_encode($event->context);
    });

    try {
        claudeClient()->complete(claudeRequest());
    } catch (LlmUnavailable $exception) {
        $logged[] = $exception->getMessage();
    }

    expect($logged)->not->toBeEmpty()
        ->and(implode('|', $logged))->not->toContain(CLAUDE_TEST_KEY);
});

it('reports a connection failure in the final exception', function (): void {
    Http::fake(fn () => throw new ConnectionException('cURL error 28'));

    expect(fn () => claudeClient()->complete(claudeRequest()))
        ->toThrow(LlmUnavailable::class, 'connection error');
});

function claudeStreamFake(): void
{
    Http::fake([ClaudeLlmClient::API_URL => Http::response(
        file_get_contents(__DIR__.'/../Fixtures/claude-stream.txt'),
        200,
        ['Content-Type' => 'text/event-stream'],
    )]);
}

it('streams text deltas that join to the full text, ending with stop reason and usage', function (): void {
    claudeStreamFake();

    $chunks = [...claudeClient()->stream(claudeRequest())];
    $last = end($chunks);

    expect(implode('', array_map(fn ($chunk) => $chunk->text, $chunks)))
        ->toBe('Merhaba, sepetinde ürün kaldı! İndirim seni bekliyor.')
        ->and($last->stopReason)->toBe('end_turn')
        ->and($last->usage->outputTokens)->toBe(15);
    Http::assertSent(fn (Request $request): bool => $request['stream'] === true);
});

it('retries the connection phase of a stream like a normal call', function (): void {
    Http::fake([ClaudeLlmClient::API_URL => Http::sequence()
        ->push(['error' => ['type' => 'overloaded_error']], 529)
        ->push((string) file_get_contents(__DIR__.'/../Fixtures/claude-stream.txt')),
    ]);

    expect(iterator_count(claudeClient()->stream(claudeRequest())))->toBe(4);
});

it('raises LlmTruncated when a stream stops at max_tokens', function (): void {
    $stream = "event: message_delta\ndata: {\"delta\":{\"stop_reason\":\"max_tokens\"},\"usage\":{\"output_tokens\":9}}\n\n"
        ."event: message_stop\ndata: {\"type\":\"message_stop\"}\n\n";
    Http::fake([ClaudeLlmClient::API_URL => Http::response($stream)]);

    expect(fn () => iterator_to_array(claudeClient()->stream(claudeRequest())))
        ->toThrow(LlmTruncated::class);
});
