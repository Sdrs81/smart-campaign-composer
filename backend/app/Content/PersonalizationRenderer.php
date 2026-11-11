<?php

declare(strict_types=1);

namespace App\Content;

/**
 * Fills {{variable}} placeholders. Every method makes one pass over the template, so cost is O(n);
 * a customer's value is inserted as plain text and never read as a placeholder again.
 */
final class PersonalizationRenderer
{
    // Spaces inside the braces are tolerated because models often write {{ first_name }}.
    private const PLACEHOLDER = '/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/';

    // Stands in for one visible character; it never joins with its neighbours into a larger grapheme.
    private const WIDTH_FILLER = 'x';

    /**
     * @param  array<string, string|null>  $values  variable name => the customer's value
     */
    public function render(string $template, array $values): string
    {
        return (string) preg_replace_callback(
            self::PLACEHOLDER,
            fn (array $match): string => Variables::has($match[1])
                ? $this->valueOrFallback($match[1], $values[$match[1]] ?? null)
                : $match[0],
            $template,
        );
    }

    /** @return list<string> placeholder names that are not registered variables, once each, in order of appearance */
    public function unknownVariables(string $template): array
    {
        preg_match_all(self::PLACEHOLDER, $template, $matches);

        return array_values(array_unique(array_filter(
            $matches[1],
            fn (string $name): bool => ! Variables::has($name),
        )));
    }

    /**
     * The longest the rendered text can get, in visible characters. A variable counts as the longer of
     * the audience's longest value and its fallback, because a customer with no value gets the fallback.
     *
     * @param  array<string, int>  $maxLengths  variable name => longest value in the audience
     */
    public function worstCaseLength(string $template, array $maxLengths): int
    {
        $worstCase = (string) preg_replace_callback(
            self::PLACEHOLDER,
            fn (array $match): string => Variables::has($match[1])
                ? str_repeat(self::WIDTH_FILLER, max($maxLengths[$match[1]] ?? 0, $this->visibleLength(Variables::fallback($match[1]))))
                : $match[0],
            $template,
        );

        return $this->visibleLength($worstCase);
    }

    /** What the reader counts: a Turkish letter, an emoji or a family emoji (joined with zero-width joiners) is 1. */
    public function visibleLength(string $text): int
    {
        $length = grapheme_strlen($text);

        // The PHP manual: it returns null or false on failure, such as invalid UTF-8. JSON-decoded text
        // cannot be invalid, so the code point count is a safe fallback.
        return is_int($length) ? $length : mb_strlen($text);
    }

    /** True when "{{" or "}}" is left over after the well-formed placeholders are removed, e.g. "{{first_name" or "{{ }}". */
    public function hasMalformedBraces(string $template): bool
    {
        $rest = (string) preg_replace(self::PLACEHOLDER, '', $template);

        // Triple braces would leave a stray single brace behind after the placeholder is removed.
        return str_contains($rest, '{{') || str_contains($rest, '}}')
            || str_contains($template, '{{{') || str_contains($template, '}}}');
    }

    private function valueOrFallback(string $name, ?string $value): string
    {
        return $value === null || trim($value) === '' ? Variables::fallback($name) : $value;
    }
}
