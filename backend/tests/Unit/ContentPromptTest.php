<?php

declare(strict_types=1);

use App\Content\ContentValidator;
use App\Content\MessageContent;
use App\Content\PersonalizationRenderer;
use App\Content\Variables;
use App\Enums\Channel;
use App\Llm\Prompts\ContentPrompt;
use App\Segment\AudienceSummary;

const CONTENT_PROMPT_SNAPSHOT = __DIR__.'/../Fixtures/content-prompt.v1.snapshot.txt';

function contentPrompt(): ContentPrompt
{
    return new ContentPrompt(160, 80, 2000);
}

/** @return array<string, array<string, mixed>> the JSON from the prompt's worked example */
function promptExample(): array
{
    preg_match('#<output>(.+?)</output>#s', contentPrompt()->system(), $match);

    return json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
}

it('matches the stored snapshot of the fixed system prompt', function (): void {
    expect(str_replace("\r\n", "\n", contentPrompt()->system()))
        ->toBe(str_replace("\r\n", "\n", (string) file_get_contents(CONTENT_PROMPT_SNAPSHOT)));
});

it('keeps the system prompt free of anything that changes per campaign or is left unfilled', function (): void {
    expect(contentPrompt()->system())
        ->not->toMatch('/\d{4}-\d{2}-\d{2}/')
        ->not->toMatch('/%[A-Z_]+%/');
});

it('quotes the limits it is given', function (): void {
    $system = (new ContentPrompt(123, 45, 6789))->system();

    expect($system)->toContain('SMS body: at most 123.')
        ->toContain('Email subject: at most 45.')
        ->toContain('Email body: at most 6789.');
});

it('lists every variable with its fallback', function (): void {
    foreach (Variables::names() as $name) {
        expect(contentPrompt()->system())->toContain('`{{'.$name.'}}` → "'.Variables::fallback($name).'"');
    }
});

it('puts the brief, tone, audience and longest values in the user message', function (): void {
    $audience = new AudienceSummary(
        ['sms' => 120, 'email' => 80],
        150,
        [],
        [
            'sms' => ['first_name' => 9, 'last_name' => 14, 'city' => 11],
            'email' => ['first_name' => 12, 'last_name' => 16, 'city' => 11],
        ],
    );

    $message = contentPrompt()->userMessage('Gold üyelere teşekkür et', 'samimi', $audience, "İstanbul'daki kadınlar seçildi.");

    expect($message)->toBe(<<<'TEXT'
<brief>Gold üyelere teşekkür et</brief>

<tone>samimi</tone>

<audience>
- sms: 120 customers can be reached
- email: 80 customers can be reached
- 150 different customers in total
- how the audience was chosen: İstanbul'daki kadınlar seçildi.
</audience>

Longest customer values (leave room for them):
- sms: {{first_name}} up to 9 characters, {{last_name}} up to 14 characters, {{city}} up to 11 characters
- email: {{first_name}} up to 12 characters, {{last_name}} up to 16 characters, {{city}} up to 11 characters
TEXT);
});

it('treats a channel missing from the audience as zero', function (): void {
    $message = contentPrompt()->userMessage('Kısa brief metni', 'resmi', new AudienceSummary([], 0, [], []), 'Açıklama');

    expect($message)->toContain('- sms: 0 customers can be reached')
        ->toContain('- sms: {{first_name}} up to 0 characters');
});

it('describes the answer as {sms:{body}, email:{subject, body}} using only supported schema features', function (): void {
    $schema = contentPrompt()->jsonSchema();

    assertSchemaNode($schema, '$');
    expect(array_keys($schema['properties']))->toBe(['sms', 'email'])
        ->and(array_keys($schema['properties']['sms']['properties']))->toBe(['body'])
        ->and(array_keys($schema['properties']['email']['properties']))->toBe(['subject', 'body'])
        ->and(json_encode($schema))->toBeString();
});

it('ships an example that follows the schema and passes the real validator', function (): void {
    $example = promptExample();
    $validator = new ContentValidator(new PersonalizationRenderer, 160, 80, 2000);

    expect(array_keys($example))->toBe(['sms', 'email'])
        ->and(array_keys($example['email']))->toBe(['subject', 'body']);
    expect($validator->validate(new MessageContent(Channel::Sms, null, $example['sms']['body']), []))->toBeNull()
        ->and($validator->validate(new MessageContent(Channel::Email, $example['email']['subject'], $example['email']['body']), []))->toBeNull();
});
