<?php

namespace DirectoryTree\ImapEngine\Selection;

use DirectoryTree\ImapEngine\SelectionOption;

class CondStore implements SelectionOption
{
    /**
     * {@inheritDoc}
     */
    public function capability(): string
    {
        return 'CONDSTORE';
    }

    /**
     * {@inheritDoc}
     */
    public function toImap(): string
    {
        return 'CONDSTORE';
    }
}
