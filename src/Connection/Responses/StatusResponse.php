<?php

namespace DirectoryTree\ImapEngine\Connection\Responses;

use DirectoryTree\ImapEngine\Connection\Tokens\Atom;

interface StatusResponse
{
    /**
     * Get the status token.
     */
    public function status(): Atom;

    /**
     * Determine if the response failed.
     */
    public function failed(): bool;

    /**
     * Determine if the response was successful.
     */
    public function successful(): bool;
}
