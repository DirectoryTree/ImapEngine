<?php

namespace DirectoryTree\ImapEngine;

class ImapSort
{
    /**
     * @param  array<int, SortCriterion>  $criteria
     */
    public function __construct(
        public array $criteria = [],
    ) {}

    /**
     * Add a sort criterion.
     */
    public function add(SortCriterion $criterion): static
    {
        $this->criteria[] = $criterion;

        return $this;
    }

    /**
     * Get the IMAP SORT criteria.
     */
    public function toImap(): string
    {
        return implode(' ', array_map(
            fn (SortCriterion $criterion) => $criterion->toImap(),
            $this->criteria,
        ));
    }
}
