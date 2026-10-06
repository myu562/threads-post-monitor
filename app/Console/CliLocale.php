<?php

namespace App\Console;

/**
 * Output language of the setup commands (contract L5): --lang=zh|en wins; without it the POSIX
 * locale variables are read in their usual order of precedence (the first non-empty one of
 * LC_ALL, LC_MESSAGES, LANG), and a value starting with "zh" gives Chinese. Default: English.
 */
final class CliLocale
{
    public const OPTION_HELP = 'Output language: zh or en (default: from LC_ALL / LC_MESSAGES / LANG, otherwise en)';

    /** zh_TW or en, or null when --lang has a value that is neither. */
    public static function resolve(?string $option): ?string
    {
        $option = $option === null ? '' : strtolower(trim($option));
        if ($option !== '') {
            return match (true) {
                str_starts_with($option, 'zh') => 'zh_TW',
                str_starts_with($option, 'en') => 'en',
                default => null,
            };
        }

        foreach (['LC_ALL', 'LC_MESSAGES', 'LANG'] as $name) {
            $value = getenv($name);
            if (is_string($value) && $value !== '') {
                return str_starts_with(strtolower($value), 'zh') ? 'zh_TW' : 'en';
            }
        }

        return 'en';
    }
}
