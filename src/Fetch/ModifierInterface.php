<?php

namespace DirectoryTree\ImapEngine\Fetch;

use DirectoryTree\ImapEngine\Enums\ImapIdentifier;

interface ModifierInterface
{
    /**
     * Get the IMAP representation of the fetch modifier.
     */
    public function toImap(ImapIdentifier $identifier): string;
}
