<?php

namespace DirectoryTree\ImapEngine;

class Folder
{
    /**
     * Constructor.
     */
    public function __construct(
        public Mailbox $mailbox,
        public string $path,
        public string $delimiter = '/',
        public array $flags = [],
    ){}

    /**
     * Get the folder name.
     */
    public function name(): string
    {
        return last(explode($this->delimiter, $this->path));
    }

    /**
     * Examine the current folder.
     */
    public function examine(): array
    {
        return $this->mailbox->connection()
            ->examineFolder($this->path)
            ->getValidatedData();
    }

    /**
     * Select the current folder.
     */
    public function select(): array
    {
        return $this->mailbox->connection()
            ->selectFolder($this->path)
            ->getValidatedData();
    }
}