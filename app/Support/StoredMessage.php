<?php

namespace App\Support;

/**
 * Messages that are stored in the database and shown later (tracked_posts.last_error, contract L4).
 *
 * They are stored as a code plus JSON parameters, "fetch.http_error|{"status":503}", and turned into
 * text only when a page is rendered, in the viewer's language (lang/{zh_TW,en}/errors.php, under
 * "stored"). Rows written before this format existed hold plain text: anything that does not parse
 * as code|{json} with a known code is shown exactly as stored.
 */
final class StoredMessage
{
    private const PATTERN = '/^([a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*)\|(\{.*\})$/s';

    /** @param  array<string, string|int|float|bool|null>  $params */
    public static function encode(string $code, array $params = []): string
    {
        $json = json_encode((object) $params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $code.'|'.($json === false ? '{}' : $json);
    }

    /** @return array{code: string, params: array<string, string>}|null */
    public static function decode(?string $raw): ?array
    {
        if ($raw === null || ! preg_match(self::PATTERN, $raw, $m)) {
            return null;
        }
        $params = json_decode($m[2], true);
        if (! is_array($params)) {
            return null;
        }
        $clean = [];
        foreach ($params as $k => $v) {
            if (! is_string($k) || ! (is_scalar($v) || $v === null)) {
                return null;
            }
            $clean[$k] = $v === null ? '' : (is_bool($v) ? ($v ? '1' : '0') : (string) $v);
        }

        return ['code' => $m[1], 'params' => $clean];
    }

    /** Text for the current language; legacy or unknown values are returned unchanged. */
    public static function render(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $decoded = self::decode($raw);
        if ($decoded === null) {
            return $raw;
        }
        $key = 'errors.stored.'.$decoded['code'];
        $text = UserMessage::key($key, $decoded['params']);

        return $text === $key ? $raw : $text;
    }
}
