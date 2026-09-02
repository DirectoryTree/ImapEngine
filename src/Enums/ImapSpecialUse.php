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
}
