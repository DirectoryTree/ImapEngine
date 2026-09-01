<?php

namespace DirectoryTree\ImapEngine;

use DirectoryTree\ImapEngine\Enums\SortDirection;

class UidOrder
{
    public function __construct(
        public SortDirection $direction,
    ) {}
}
