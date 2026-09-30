<?php

namespace DirectoryTree\ImapEngine\Connection;

use InvalidArgumentException;
use Stringable;

readonly class CommandPart implements Stringable
{
    /**
     * The command part value.
     */
    protected string $value;

    /**
     * Constructor.
     */
    public function __construct(string|int $value)
    {
        $value = (string) $value;

        if (preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new InvalidArgumentException('Invalid IMAP command part.');
        }

        $this->value = $value;
    }

    /**
     * Get the command part value.
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
