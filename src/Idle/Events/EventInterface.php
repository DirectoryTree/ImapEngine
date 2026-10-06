<?php

namespace DirectoryTree\ImapEngine\Idle\Events;

interface EventInterface
{
    /**
     * Get the folder path.
     */
    public function folder(): string;

    /**
     * Get the event type, using the protocol response type for server events.
     */
    public function type(): string;
}
