<?php

namespace DirectoryTree\ImapEngine\Connection\Data;

use DirectoryTree\ImapEngine\Connection\Tokens\Token;
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

    /**
     * Get all of the token's values.
     */
    public function values(): array
    {
        return array_map(fn (Token $token) => $token->value, $this->tokens);
    }
}
