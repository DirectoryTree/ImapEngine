<?php

namespace DirectoryTree\ImapEngine\Fetch;

interface ModifierInterface
{
    /**
     * Get the IMAP representation of the fetch modifier.
     */
    public function toImap(): string;
}
