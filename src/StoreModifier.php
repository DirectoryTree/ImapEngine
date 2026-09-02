<?php

namespace DirectoryTree\ImapEngine;

interface StoreModifier
{
    /**
     * Get the IMAP representation of the store modifier.
     */
    public function toImap(): string;
}
