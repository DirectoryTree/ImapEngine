<?php

namespace DirectoryTree\ImapEngine\Connection\Responses;

use Illuminate\Contracts\Support\Arrayable;
use Stringable;

abstract class Response implements Arrayable, Stringable
{
    /**
     * Constructor.
     */
    public function __construct(
        protected array $tokens,
    ) {}

    /**
     * Get the response tokens.
     */
    public function tokens(): array
    {
        return $this->tokens;
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return $this->tokens;
    }

    /**
     * Get a JSON representation of the response tokens.
     */
    public function __toString(): string
    {
        return implode(' ', $this->tokens);
    }
}
