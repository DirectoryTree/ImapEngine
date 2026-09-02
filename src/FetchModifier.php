<?php

namespace DirectoryTree\ImapEngine;

interface FetchModifier
{
    /**
     * Get the IMAP representation of the fetch modifier.
     */
    public function toImap(): string;
}
