<?php

namespace DirectoryTree\ImapEngine\Store;

interface ModifierInterface
{
    /**
     * Get the IMAP representation of the store modifier.
     */
    public function toImap(): string;
}
