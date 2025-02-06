<?php

namespace DirectoryTree\ImapEngine\Connection\Responses;

use DirectoryTree\ImapEngine\Connection\Tokens\Atom;

trait HasStatus
{
    /**
     * Get the status token.
     */
    abstract public function status(): Atom;

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
