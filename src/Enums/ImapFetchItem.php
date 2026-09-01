<?php

namespace DirectoryTree\ImapEngine\Enums;

enum ImapFetchItem: string
{
    case Flags = 'flags';
    case Size = 'size';
    case Headers = 'headers';
    case Body = 'body';
    case BodyStructure = 'body-structure';

    /**
     * Get the IMAP FETCH data item.
     */
    public function command(bool $leaveUnread = true): string
    {
        return match ($this) {
            self::Flags => 'FLAGS',
            self::Size => 'RFC822.SIZE',
            self::Headers => $leaveUnread ? 'BODY.PEEK[HEADER]' : 'BODY[HEADER]',
            self::Body => $leaveUnread ? 'BODY.PEEK[TEXT]' : 'BODY[TEXT]',
            self::BodyStructure => 'BODYSTRUCTURE',
        };
    }
}
