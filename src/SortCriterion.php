<?php

namespace DirectoryTree\ImapEngine;

use DirectoryTree\ImapEngine\Enums\ImapSortKey;
use DirectoryTree\ImapEngine\Enums\SortDirection;

class SortCriterion
{
    public function __construct(
        public ImapSortKey $key,
        public SortDirection $direction = SortDirection::Ascending,
    ) {}

    /**
     * Get the IMAP SORT criterion.
     */
    public function toImap(): string
    {
        return match ($this->direction) {
            SortDirection::Ascending => $this->key->value,
            SortDirection::Descending => "REVERSE {$this->key->value}",
        };
    }
}
