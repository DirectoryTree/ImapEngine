<?php

namespace DirectoryTree\ImapEngine\Idle\Events;

interface EventInterface
{
    /**
     * Get the folder path.
     */
    public function folder(): string;

    /**
     * Get the protocol response type, or SELECT for a folder selection event.
     */
    public function type(): string;
}
