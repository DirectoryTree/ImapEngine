<?php

namespace DirectoryTree\ImapEngine\Selection;

class CondStore implements OptionInterface
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
