<?php

namespace DirectoryTree\ImapEngine\Selection;

use DirectoryTree\ImapEngine\SelectionOption;
use DirectoryTree\ImapEngine\Support\Str;

class QuickResync implements SelectionOption
{
    /**
     * Constructor.
     */
    public function __construct(
        protected int $uidValidity,
        protected int $highestModSequence,
        protected array $knownUids = [],
    ) {}

    /**
     * {@inheritDoc}
     */
    public function capability(): string
    {
        return 'QRESYNC';
    }

    /**
     * {@inheritDoc}
     */
    public function toImap(): string
    {
        $parameters = [$this->uidValidity, $this->highestModSequence];

        if ($this->knownUids) {
            $parameters[] = Str::set($this->knownUids);
        }

        return 'QRESYNC '.Str::list($parameters);
    }
}
