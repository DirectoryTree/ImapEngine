<?php

namespace DirectoryTree\ImapEngine\Enums;

enum ImapSearchKey: string
{
    case Cc = 'CC';
    case On = 'ON';
    case To = 'TO';
    case All = 'ALL';
    case New = 'NEW';
    case Old = 'OLD';
    case Bcc = 'BCC';
    case Uid = 'UID';
    case Seen = 'SEEN';
    case Body = 'BODY';
    case From = 'FROM';
    case Text = 'TEXT';
    case Draft = 'DRAFT';
    case Since = 'SINCE';
    case Recent = 'RECENT';
    case Unseen = 'UNSEEN';
    case Before = 'BEFORE';
    case Header = 'HEADER';
    case Deleted = 'DELETED';
    case Flagged = 'FLAGGED';
    case Keyword = 'KEYWORD';
    case Subject = 'SUBJECT';
    case Answered = 'ANSWERED';
    case Undeleted = 'UNDELETED';
    case Unflagged = 'UNFLAGGED';
    case Unanswered = 'UNANSWERED';
}
