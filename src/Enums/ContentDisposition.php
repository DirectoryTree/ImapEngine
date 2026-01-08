<?php

namespace DirectoryTree\ImapEngine\Enums;

/**
 * @see https://datatracker.ietf.org/doc/html/rfc2183
 */
enum ContentDisposition: string
{
    case Inline = 'inline';
    case Attachment = 'attachment';
}
