<?php

namespace DirectoryTree\ImapEngine\Collections;

use DirectoryTree\ImapEngine\Message;
use DirectoryTree\ImapEngine\MessageInterface;

class MessageCollection extends PaginatedCollection
{
    /**
     * Find a message by its UID.
     */
    public function find(int $uid): ?MessageInterface
    {
        return $this->first(
            fn (MessageInterface $message) => $message->uid() === $uid
        );
    }

    /**
     * Find a message by its UID or throw an exception.
     */
    public function findOrFail(int $uid): MessageInterface
    {
        return $this->firstOrFail(
            fn (MessageInterface $message) => $message->uid() === $uid
        );
    }
}
