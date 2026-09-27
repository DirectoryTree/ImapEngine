<?php

namespace DirectoryTree\ImapEngine;

interface FolderDataItemInterface
{
    /**
     * Get the unique folder data item key.
     */
    public function key(): string;

    /**
     * Get the IMAP representation of the folder data item.
     */
    public function toImap(): string;

    /**
     * Get the capability required to request the folder data item.
     */
    public function capability(): string;
}
