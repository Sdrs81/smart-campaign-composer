<?php

declare(strict_types=1);

use App\Llm\Exceptions\LlmBadResponse;
use App\Llm\Exceptions\LlmUnavailable;
use App\Llm\LlmChunk;
use App\Llm\LlmUsage;
use App\Llm\SseParser;

function streamFixture(): string
{
    return str_replace("\r\n", "\n", (string) file_get_contents(__DIR__.'/../Fixtures/claude-stream.txt'));
}

/**
 * @param  list<string>  $pieces
 * @return list<LlmChunk>
 */
function parseInPieces(array $pieces): array
{
    $parser = new SseParser;
    $chunks = [];

    foreach ($pieces as $piece) {
        array_push($chunks, ...$parser->feed($piece));
    }
    array_push($chunks, ...$parser->finish());

    return $chunks;
}

/** @return list<string> */
function splitAt(string $text, array $offsets): array
{
    $pieces = [];
    $previous = 0;
    foreach ($offsets as $offset) {
        $pieces[] = substr($text, $previous, $offset - $previous);
        $previous = $offset;
    }
    $pieces[] = substr($text, $previous);

    return $pieces;
}

it('turns the fixture into text chunks plus one final chunk with stop reason and usage', function (): void {
    $chunks = parseInPieces([streamFixture()]);

    expect($chunks)->toEqual([
        new LlmChunk('Merhaba, '),
        new LlmChunk('sepetinde ürün kaldı'),
        new LlmChunk('! İndirim seni bekliyor.'),
        new LlmChunk('', 'end_turn', new LlmUsage(25, 15)),
    ]);
});

it('yields the same chunks when the stream is cut at any single byte', function (): void {
    $fixture = streamFixture();
    $expected = parseInPieces([$fixture]);

    for ($offset = 1; $offset < strlen($fixture); $offset++) {
        expect(parseInPieces(splitAt($fixture, [$offset])))->toEqual($expected);
    }
});

it('yields the same chunks for many random multi-way cuts, even one byte at a time', function (): void {
    $fixture = streamFixture();
    $expected = parseInPieces([$fixture]);
    mt_srand(20260101);

    for ($round = 0; $round < 200; $round++) {
        $offsets = array_unique(array_map(fn () => mt_rand(1, strlen($fixture) - 1), range(1, mt_rand(2, 40))));
        sort($offsets);
        expect(parseInPieces(splitAt($fixture, $offsets)))->toEqual($expected);
    }

    expect(parseInPieces(str_split($fixture)))->toEqual($expected);
});

it('accepts CRLF line endings', function (): void {
    $crlf = str_replace("\n", "\r\n", streamFixture());

    expect(parseInPieces([$crlf]))->toEqual(parseInPieces([streamFixture()]));
});

it('joins multi-line data fields with a newline before decoding', function (): void {
    $stream = "event: message_start\ndata: {\"type\":\"message_start\",\n"
        ."data: \"message\":{\"usage\":{\"input_tokens\":3}}}\n\n"
        ."event: message_delta\ndata: {\"delta\":{\"stop_reason\":\"end_turn\"},\"usage\":{\"output_tokens\":1}}\n\n"
        ."event: message_stop\ndata: {\"type\":\"message_stop\"}\n\n";

    expect(parseInPieces([$stream]))->toEqual([new LlmChunk('', 'end_turn', new LlmUsage(3, 1))]);
});

it('rejects a stop_reason that is not a string as a bad response', function (): void {
    $stream = "event: message_delta\ndata: {\"delta\":{\"stop_reason\":{\"x\":1}},\"usage\":{\"output_tokens\":1}}\n\n";

    expect(fn () => parseInPieces([$stream]))->toThrow(LlmBadResponse::class, 'stop_reason that is not a string');
});

it('ignores ping events and comment lines', function (): void {
    expect(array_filter(parseInPieces([streamFixture()]), fn (LlmChunk $chunk): bool => $chunk->text === 'ping'))
        ->toBeEmpty();
});

it('turns an overloaded error event into LlmUnavailable', function (): void {
    $stream = "event: error\ndata: {\"type\":\"error\",\"error\":{\"type\":\"overloaded_error\",\"message\":\"Overloaded\"}}\n\n";

    expect(fn () => parseInPieces([$stream]))->toThrow(LlmUnavailable::class, 'overloaded_error: Overloaded');
});

it('turns any other error event into LlmBadResponse', function (): void {
    $stream = "event: error\ndata: {\"type\":\"error\",\"error\":{\"type\":\"invalid_request_error\",\"message\":\"bad\"}}\n\n";

    expect(fn () => parseInPieces([$stream]))->toThrow(LlmBadResponse::class, 'invalid_request_error: bad');
});

it('raises an error event that arrives mid-stream, after text was already produced', function (): void {
    $parser = new SseParser;
    $parser->feed("event: content_block_delta\ndata: {\"delta\":{\"type\":\"text_delta\",\"text\":\"Hi\"}}\n\n");

    expect(fn () => $parser->feed("event: error\ndata: {\"error\":{\"type\":\"api_error\",\"message\":\"boom\"}}\n\n"))
        ->toThrow(LlmBadResponse::class, 'api_error: boom');
});

it('fails when the stream ends before message_stop', function (): void {
    $truncated = substr(streamFixture(), 0, (int) strpos(streamFixture(), 'event: message_delta'));

    expect(fn () => parseInPieces([$truncated]))->toThrow(LlmBadResponse::class, 'message_stop');
});

it('reports invalid JSON data with the event name', function (): void {
    expect(fn () => parseInPieces(["event: content_block_delta\ndata: {nope\n\n"]))
        ->toThrow(LlmBadResponse::class, 'content_block_delta');
});
