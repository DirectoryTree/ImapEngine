<?php

namespace DirectoryTree\ImapEngine\Connection\Data;

use Stringable;

abstract class Data implements Stringable
{
    /**
     * Constructor.
     */
    public function __construct(
        protected array $tokens
    ) {}

    /**
     * Get the tokens.
     */
    public function tokens(): array
    {
        return $this->tokens;
    }
}
