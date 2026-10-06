<?php

namespace App\Http\Exceptions;

use App\Support\InvalidInput;
use RuntimeException;

/** An InvalidInput raised while saving one field of the post edit form, tagged with that field. */
final class MetaFieldError extends RuntimeException
{
    public function __construct(public readonly string $field, InvalidInput $previous)
    {
        parent::__construct($previous->getMessage(), 0, $previous);
    }
}
