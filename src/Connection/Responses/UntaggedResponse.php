<?php

namespace DirectoryTree\ImapEngine\Connection\Responses;

use DirectoryTree\ImapEngine\Connection\Tokens\Atom;

class UntaggedResponse extends Response
{
    /**
     * Get the response type token.
     */
    public function type(): Atom
    {
        return $this->tokens[1];
    }

    /**
     * Get the data tokens.
     *
     * @return Atom[]
     */
    public function data(): array
    {
        return array_slice($this->tokens, 2);
    }
}
