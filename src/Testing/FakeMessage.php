<?php

namespace DirectoryTree\ImapEngine\Testing;

use DirectoryTree\ImapEngine\HasParsedMessage;
use DirectoryTree\ImapEngine\MessageInterface;

class FakeMessage implements MessageInterface
{
    use HasParsedMessage;

    /**
     * Constructor.
     */
    public function __construct(
        protected int $uid,
        protected array $flags = [],
        protected string $contents = '',
    ) {}

    /**
     * {@inheritDoc}
     */
    public function uid(): int
    {
        return $this->uid;
    }

    /**
     * {@inheritDoc}
     */
    public function is(MessageInterface $message): bool
    {
        return $message instanceof self
            && $this->uid === $message->uid
            && $this->flags === $message->flags
            && $this->contents === $message->contents;
    }

    /**
     * {@inheritDoc}
     */
    protected function isEmpty(): bool
    {
        return empty($this->contents);
    }

    /**
     * {@inheritDoc}
     */
    public function __toString(): string
    {
        return $this->contents;
    }
}
