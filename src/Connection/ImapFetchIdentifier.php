<?php

namespace DirectoryTree\ImapEngine\Connection;

enum ImapFetchIdentifier
{
    case Uid;
    case MessageNumber;
}
