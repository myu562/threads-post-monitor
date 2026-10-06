<?php

namespace App\Support;

use InvalidArgumentException;

/** Rejected user input (a URL, a tag name…). The message is a translation key, see TranslatableMessage. */
class InvalidInput extends InvalidArgumentException implements TranslatableMessage
{
    /** @param  array<string, string|int|float>  $params */
    public function __construct(private readonly string $key, private readonly array $params = [])
    {
        parent::__construct($key);
    }

    public function translationKey(): string
    {
        return $this->key;
    }

    public function translationParams(): array
    {
        return $this->params;
    }
}
