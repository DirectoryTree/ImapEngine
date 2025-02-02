<?php

namespace DirectoryTree\ImapEngine\Exceptions;

use Exception;
use Illuminate\Support\Arr;

class ImapServerErrorException extends Exception
{
    /**
     * Create a new exception instance from the given response tokens.
     */
    public static function fromResponseTokens(array $tokens): static
    {
        return new self(implode(' ', Arr::flatten($tokens)));
    }
}
