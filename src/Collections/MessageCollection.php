<?php

namespace DirectoryTree\ImapEngine\Collections;

use DirectoryTree\ImapEngine\Message;

class MessageCollection extends PaginatedCollection
{
    /**
     * Find a message by its UID.
     */
    public function find(int $uid): ?Message
    {
        return $this->first(
            fn (Message $message) => $message->uid() === $uid
        );
    }

    /**
     * Find a message by its UID or throw an exception.
     */
    public function findOrFail(int $uid): Message
    {
        return $this->firstOrFail(
            fn (Message $message) => $message->uid() === $uid
        );
    }
}
