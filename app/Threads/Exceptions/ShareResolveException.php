<?php

namespace App\Threads\Exceptions;

use App\Support\TranslatableMessage;
use RuntimeException;

/**
 * A Threads /share/ link could not be turned into a post URL. The message is a translation key
 * (lang/{zh_TW,en}/errors.php, "share.*"); show it with App\Support\UserMessage::of().
 */
class ShareResolveException extends RuntimeException implements TranslatableMessage
{
    public function __construct(private readonly string $key)
    {
        parent::__construct($key);
    }

    public function translationKey(): string
    {
        return $this->key;
    }

    public function translationParams(): array
    {
        return [];
    }
}
