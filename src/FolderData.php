<?php

namespace DirectoryTree\ImapEngine;

enum FolderData: string implements FolderDataItem
{
    case SpecialUse = 'SPECIAL-USE';

    /**
     * {@inheritDoc}
     */
    public function key(): string
    {
        return $this->value;
    }

    /**
     * {@inheritDoc}
     */
    public function toImap(): string
    {
        return $this->value;
    }

    /**
     * {@inheritDoc}
     */
    public function capability(): string
    {
        return $this->value;
    }
}
