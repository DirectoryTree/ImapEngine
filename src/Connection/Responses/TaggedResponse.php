<?php

namespace DirectoryTree\ImapEngine\Connection\Responses;

use DirectoryTree\ImapEngine\Connection\Tokens\Atom;

class TaggedResponse extends Response
{
    /**
     * Get the response tag.
     */
    public function tag(): Atom
    {
        return $this->tokens[0];
    }

    /**
     * Get the response status token.
     */
    public function status(): Atom
    {
        return $this->tokens[1];
    }

    /**
     * Get the response data tokens.
     *
     * @return Atom[]
     */
    public function data(): array
    {
        return array_slice($this->tokens, 2);
    }

    /**
     * Determine if the response was successful.
     */
    public function successful(): bool
    {
        return $this->status()->value === 'OK';
    }

    /**
     * Determine if the response failed.
     */
    public function failed(): bool
    {
        return in_array($this->status()->value, ['NO', 'BAD']);
    }
}
