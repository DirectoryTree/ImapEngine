<?php

namespace DirectoryTree\ImapEngine\Enums;

/**
 * @see https://datatracker.ietf.org/doc/html/rfc6154#section-2
 */
enum ImapSpecialUse: string
{
    case All = '\\All';
    case Archive = '\\Archive';
    case Drafts = '\\Drafts';
    case Flagged = '\\Flagged';
    case Junk = '\\Junk';
    case Sent = '\\Sent';
    case Trash = '\\Trash';

    /**
     * Get conventional folder names for the special use.
     *
     * @return string[]
     */
    public function fallbackNames(): array
    {
        return match ($this) {
            self::All => ['all', 'all mail', 'all messages'],
            self::Archive => ['archive', 'archives'],
            self::Drafts => ['draft', 'drafts'],
            self::Flagged => ['flagged', 'starred'],
            self::Junk => ['junk', 'junk mail', 'junk email', 'spam', 'bulk mail'],
            self::Sent => ['sent', 'sent mail', 'sent items', 'sent messages'],
            self::Trash => ['trash', 'deleted', 'deleted items', 'deleted messages', 'bin'],
        };
    }
}
