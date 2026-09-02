<?php

namespace DirectoryTree\ImapEngine;

interface SelectionOption
{
    /**
     * Get the capability required by the selection option.
     */
    public function capability(): string;

    /**
     * Get the IMAP representation of the selection option.
     */
    public function toImap(): string;
}
