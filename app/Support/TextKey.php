<?php

namespace App\Support;

use Normalizer;

/**
 * Comparison key for names that the database compares with an accent- and case-insensitive
 * collation (MySQL utf8mb4_unicode_ci): compatibility decomposition (NFKD, so full-width letters are
 * plain ones), combining marks removed (é is e, İ is I), then lower-cased. It is as close to unicode_ci
 * as PHP gets cheaply; the remaining differences (ß = ss, for example) are caught by the unique
 * index and handled where names are written.
 */
final class TextKey
{
    public static function fold(string $value): string
    {
        if (class_exists(Normalizer::class)) {
            $decomposed = Normalizer::normalize($value, Normalizer::FORM_KD);
            if (is_string($decomposed)) {
                $value = (string) preg_replace('/\p{Mn}+/u', '', $decomposed);
            }
        }

        return mb_strtolower($value, 'UTF-8');
    }
}
