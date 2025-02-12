<?php

namespace DirectoryTree\ImapEngine\Enums;

enum ImapSearchCriteria: string
{
    case All = 'ALL';
    case New = 'NEW';
    case Old = 'OLD';
    case Seen = 'SEEN';
    case Recent = 'RECENT';
    case Unseen = 'UNSEEN';
    case Deleted = 'DELETED';
    case Answered = 'ANSWERED';
    case Undeleted = 'UNDELETED';
    case Unflagged = 'UNFLAGGED';
    case Unanswered = 'UNANSWERED';
}
