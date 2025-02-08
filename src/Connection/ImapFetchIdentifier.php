<?php

namespace DirectoryTree\ImapEngine\Connection;

enum ImapFetchOption
{
    case Uid;
    case MessageNumber;
}
