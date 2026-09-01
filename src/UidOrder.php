<?php

namespace DirectoryTree\ImapEngine;

use DirectoryTree\ImapEngine\Enums\SortDirection;

class UidOrder
{
    /**
     * Constructor.
     */
    public function __construct(
        public SortDirection $direction,
    ) {}
}
