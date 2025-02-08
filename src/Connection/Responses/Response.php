<?php

namespace DirectoryTree\ImapEngine\Connection\Responses;

use DirectoryTree\ImapEngine\Connection\Data\Data;
use DirectoryTree\ImapEngine\Connection\Tokens\Token;
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
     * Get the response token at the given index.
     */
    public function tokenAt(int $index): Token|Data|null
    {
        return $this->tokens[$index] ?? null;
    }

    /**
     * Get the response tokens after the given index.
     */
    public function tokensAfter(int $index): array
    {
        return array_slice($this->tokens, $index);
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return array_map(function (Token|Data $token) {
            return $token instanceof Data ? $token->values() : $token->value;
        }, $this->tokens);
    }

    /**
     * Get a JSON representation of the response tokens.
     */
    public function __toString(): string
    {
        return implode(' ', $this->tokens);
    }
}
