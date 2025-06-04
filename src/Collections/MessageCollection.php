<?php

namespace DirectoryTree\ImapEngine\Collections;

use DirectoryTree\ImapEngine\MessageInterface;

/**
 * @template-extends PaginatedCollection<array-key, \DirectoryTree\ImapEngine\MessageInterface|\DirectoryTree\ImapEngine\Message>
 */
class MessageCollection extends PaginatedCollection
{
    /**
     * Find a message by its UID.
     *
     * @return \DirectoryTree\ImapEngine\Message|null
     */
    public function find(int $uid): ?MessageInterface
    {
        return $this->first(
            fn (MessageInterface $message) => $message->uid() === $uid
        );
    }

    /**
     * Find a message by its UID or throw an exception.
     *
     * @return \DirectoryTree\ImapEngine\Message
     */
    public function findOrFail(int $uid): MessageInterface
    {
        return $this->firstOrFail(
            fn (MessageInterface $message) => $message->uid() === $uid
        );
    }
}
