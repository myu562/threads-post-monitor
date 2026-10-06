<?php

namespace App\Support;

/**
 * An exception whose user-facing message is a translation key (lang/{zh_TW,en}/*.php) plus
 * replacement values. getMessage() returns the key itself; render it with UserMessage::of().
 */
interface TranslatableMessage
{
    public function translationKey(): string;

    /** @return array<string, string|int|float> */
    public function translationParams(): array;
}
