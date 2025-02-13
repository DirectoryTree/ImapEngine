<?php

namespace DirectoryTree\ImapEngine\Exceptions;

use DirectoryTree\ImapEngine\Connection\ImapCommand;
use DirectoryTree\ImapEngine\Connection\Responses\Response;

class ImapCommandException extends Exception
{
    /**
     * Make a new instance from a failed command and response.
     */
    public static function make(ImapCommand $command, Response $response): static
    {
        return new static(sprintf('IMAP command "%s" failed. Response: "%s"', $command, $response));
    }
}
