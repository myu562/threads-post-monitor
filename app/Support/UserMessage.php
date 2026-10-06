<?php

namespace App\Support;

use Illuminate\Support\Facades\Lang;
use Throwable;

/** Turns an exception or a translation key into text in the current interface language. */
final class UserMessage
{
    public static function of(Throwable $e): string
    {
        if ($e instanceof TranslatableMessage) {
            return self::key($e->translationKey(), $e->translationParams());
        }

        return self::key($e->getMessage());
    }

    /**
     * The translation of $key, or $key itself when it is not a known key (so a plain message from
     * an older code path is still shown as is, never as an array or an empty string).
     *
     * @param  array<string, mixed>  $params
     */
    public static function key(string $key, array $params = []): string
    {
        if ($key === '' || ! Lang::has($key)) {
            return $key;
        }
        $text = __($key, array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $params));

        return is_string($text) ? $text : $key;
    }
}
