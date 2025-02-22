<?php

namespace DirectoryTree\ImapEngine;

use BadMethodCallException;

class FileMessage implements MessageInterface
{
    use HasParsedMessage;

    /**
     * Constructor.
     */
    public function __construct(
        protected string $contents
    ) {}

    /**
     * {@inheritDoc}
     */
    public function uid(): int
    {
        throw new BadMethodCallException('FileMessage does not support a UID');
    }

    /**
     * Get the string representation of the message.
     */
    public function __toString(): string
    {
        return $this->contents;
    }

    /**
     * Determine if the message is empty.
     */
    protected function isEmpty(): bool
    {
        return empty($this->contents);
    }
}
