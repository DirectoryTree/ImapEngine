<?php

namespace DirectoryTree\ImapEngine\Pagination;

use DirectoryTree\ImapEngine\Exceptions\InvalidCursorException;
use DirectoryTree\ImapEngine\MailboxInterface;

class Cursor
{
    /**
     * Create a cursor for resuming a folder scan.
     */
    public function __construct(
        public readonly string $mailbox,
        public readonly string $folder,
        public readonly int $uidValidity,
        public readonly int $uid,
        public readonly int $maxUid,
        public readonly string $direction,
    ) {}

    /**
     * Identify the mailbox without including account details in the cursor.
     */
    public static function mailboxFingerprint(MailboxInterface $mailbox): string
    {
        return hash('sha256', json_encode([
            $mailbox->config('host'),
            (int) $mailbox->config('port', 993),
            $mailbox->config('username'),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Assert that the cursor can resume the current folder scan.
     */
    public function assertMatches(string $mailbox, string $folder, int $uidValidity, string $direction): void
    {
        if (
            $this->mailbox !== $mailbox
            || $this->folder !== $folder
            || $this->uidValidity !== $uidValidity
            || $this->direction !== $direction
        ) {
            throw new InvalidCursorException('The cursor must match the mailbox, folder, UID validity, and fetch order.');
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
