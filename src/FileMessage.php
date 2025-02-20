<?php

namespace DirectoryTree\ImapEngine;

use Stringable;

class FileMessage implements Stringable
{
    use HasParsedMessage;

    /**
     * Constructor.
     */
    public function __construct(
        protected string $contents
    ) {}

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
