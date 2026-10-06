<?php

namespace App\Threads\Exceptions;

use RuntimeException;

class ParseException extends RuntimeException
{
    public const POST_NOT_FOUND = 'post_not_found';
    public const EMPTY_BODY = 'empty_body';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
