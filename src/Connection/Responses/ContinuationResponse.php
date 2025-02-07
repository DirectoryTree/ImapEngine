<?php

namespace DirectoryTree\ImapEngine\Connection\Responses;

use DirectoryTree\ImapEngine\Connection\Tokens\Atom;

class ContinuationResponse extends Response
{
    /**
     * Get the data tokens.
     *
     * @return Atom[]
     */
    public function data(): array
    {
        return array_slice($this->tokens, 1);
    }
}
