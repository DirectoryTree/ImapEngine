<?php

namespace DirectoryTree\ImapEngine\Connection\Tokens;

use Stringable;

abstract class Token implements Stringable
{
    /**
     * Constructor.
     */
    public function __construct(
        public string $value,
    ) {}

    /**
     * Get the token's value.
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
