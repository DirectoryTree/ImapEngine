<?php

namespace DirectoryTree\ImapEngine\Connection;

/**
 * A command argument assembled from syntax fragments and IMAP literals.
 */
class ImapCommandArgument
{
    /**
     * Create an argument whose parts are joined without inserting spaces.
     *
     * @param  list<string|array{string, string}>  $parts  Syntax fragments or pairs of literal markers and payloads.
     */
    public function __construct(
        public readonly array $parts,
    ) {}

    /**
     * Determine if any literal contains non-ASCII bytes.
     */
    public function hasNonAsciiLiteral(): bool
    {
        foreach ($this->parts as $part) {
            if (is_array($part) && preg_match('/[\x80-\xFF]/', $part[1])) {
                return true;
            }
        }

        return false;
    }
}
