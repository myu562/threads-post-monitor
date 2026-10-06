<?php

namespace App\Threads;

final readonly class FetchResult
{
    public const OK = 'ok';
    public const GONE = 'gone';
    public const BLOCKED = 'blocked';
    public const PARSE_FAILED = 'parse_failed';
    public const NETWORK_ERROR = 'network_error';
    public const HTTP_ERROR = 'http_error';

    public bool $ok;

    public function __construct(
        public string $status,
        public ?int $httpCode = null,
        public ?PostData $data = null,
        public ?string $error = null,
        public ?string $rawExcerpt = null,
        // What the page shows for this failure: a code under errors.stored in lang/ (contract L4)
        // and its parameters. $error stays an English diagnostic for logs and tests.
        public ?string $code = null,
        public array $params = [],
    ) {
        $this->ok = $status === self::OK && $data !== null;
    }

    public static function success(int $httpCode, PostData $data, ?string $rawExcerpt = null): self
    {
        return new self(self::OK, $httpCode, $data, null, $rawExcerpt);
    }

    /** @param  array<string, string|int>  $params */
    public static function failure(string $status, ?int $httpCode, string $error, ?string $rawExcerpt = null, ?string $code = null, array $params = []): self
    {
        return new self($status, $httpCode, null, $error, $rawExcerpt, $code ?? 'fetch.'.$status, $params);
    }
}
