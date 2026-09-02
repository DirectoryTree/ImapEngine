<?php

namespace DirectoryTree\ImapEngine;

class ImapSort
{
    /**
     * The sort criteria.
     *
     * @var array<int, SortCriterion>
     */
    public array $criteria;

    /**
     * Constructor.
     */
    public function __construct(SortCriterion $criterion, SortCriterion ...$criteria)
    {
        $this->criteria = [$criterion, ...$criteria];
    }

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
