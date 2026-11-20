<?php

namespace DirectoryTree\ImapEngine\Pagination;

use DirectoryTree\ImapEngine\Exceptions\InvalidCursorException;

class Cursor
{
    /**
     * Create a cursor for resuming a folder scan.
     */
    public function __construct(
        public readonly string $folder,
        public readonly int $uidValidity,
        public readonly int $uid,
        public readonly int $maxUid,
        public readonly string $direction,
    ) {}

    /**
     * Assert that the cursor can resume the current folder scan.
     */
    public function assertMatches(string $folder, int $uidValidity, string $direction): void
    {
        if (
            $this->folder !== $folder
            || $this->uidValidity !== $uidValidity
            || $this->direction !== $direction
        ) {
            throw new InvalidCursorException('The cursor must match the folder, UID validity, and fetch order.');
        }
    }

    /**
     * Encode the cursor for storage.
     */
    public function encode(): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(json_encode($this, JSON_THROW_ON_ERROR)));
    }

    /**
     * Restore a previously encoded cursor.
     */
    public static function fromEncoded(string $encoded): static
    {
        return new static(...json_decode(
            base64_decode(str_replace(['-', '_'], ['+', '/'], $encoded)),
            true,
            flags: JSON_THROW_ON_ERROR,
        ));
    }
}
