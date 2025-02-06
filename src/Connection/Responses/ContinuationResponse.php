<?php

namespace DirectoryTree\ImapEngine\Connection\Responses;

use DirectoryTree\ImapEngine\Connection\Tokens\Atom;

class ContinuationResponse extends Response
{
    /**
     * Get the continuation messages.
     *
     * @return Atom[]
     */
    public function messages(): array
    {
        return array_slice($this->tokens, 1);
    }
}
