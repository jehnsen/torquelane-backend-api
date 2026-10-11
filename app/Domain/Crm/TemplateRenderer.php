<?php

declare(strict_types=1);

namespace App\Domain\Crm;

/**
 * Fills a message template: `{{name}}` becomes the value given for `name`.
 * A placeholder nothing was given for becomes empty, never left in the text a
 * customer reads. Values are inserted as they are (no nested rendering).
 */
final class TemplateRenderer
{
    private const string PLACEHOLDER = '/\{\{\s*([a-z][a-z0-9_]*)\s*\}\}/i';

    /**
     * @param  array<string, string>  $values
     */
    public static function render(string $template, array $values): string
    {
        $values = array_change_key_case($values, CASE_LOWER);

        return (string) preg_replace_callback(self::PLACEHOLDER, fn (array $match): string => $values[strtolower($match[1])] ?? '', $template);
    }

    /**
     * The placeholders a template uses, in order of first appearance.
     *
     * @return list<string>
     */
    public static function placeholders(string $template): array
    {
        preg_match_all(self::PLACEHOLDER, $template, $matches);

        return array_values(array_unique(array_map(strtolower(...), $matches[1])));
    }
}
