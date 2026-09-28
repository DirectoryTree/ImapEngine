<?php

namespace DirectoryTree\ImapEngine\Idle\Events;

use DirectoryTree\ImapEngine\Selection\Result;

class FolderSelected implements EventInterface
{
    /**
     * Constructor. A selection signals that the folder needs reconciliation.
     */
    public function __construct(
        protected string $folder,
        protected Result $selection,
    ) {}

    /**
     * Get the folder path.
     */
    public function folder(): string
    {
        return $this->folder;
    }

    /**
     * Get the event type.
     */
    public function type(): string
    {
        return 'SELECT';
    }

    /**
     * Get the selection result after connecting or reconnecting.
     */
    public function selection(): Result
    {
        return $this->selection;
    }
}
