<?php

namespace DirectoryTree\ImapEngine\Enums;

enum ImapAuthMechanism: string
{
    case XOAuth2 = 'XOAUTH2';
    case External = 'EXTERNAL';
}
