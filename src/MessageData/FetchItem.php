<?php

namespace DirectoryTree\ImapEngine\MessageData;

interface FetchItem
{
    /**
     * Get the unique message data item key.
     */
    public function key(): string;

    /**
     * Get the IMAP representation of the message data item.
     */
    public function toImap(): string;
}
