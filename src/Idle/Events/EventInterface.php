<?php

namespace DirectoryTree\ImapEngine\Idle\Events;

interface EventInterface
{
    /**
     * Get the folder path.
     */
    public function folder(): string;

    /**
     * Get the protocol response type, or SELECT after connecting.
     */
    public function type(): string;
}
