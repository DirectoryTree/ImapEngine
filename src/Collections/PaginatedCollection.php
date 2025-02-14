<?php

namespace DirectoryTree\ImapEngine\Collections;

use DirectoryTree\ImapEngine\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class PaginatedCollection extends Collection
{
    /**
     * The total number of items.
     */
    protected int $total = 0;

    /**
     * Paginate the current collection.
     */
    public function paginate(int $perPage = 15, ?int $page = null, string $pageName = 'page', bool $prepaginated = false): LengthAwarePaginator
    {
        $total = $this->total ?: $this->count();

        $results = ! $prepaginated && $total ? $this->forPage($page, $perPage) : $this;

        return $this->paginator($results, $total, $perPage, $page, $pageName);
    }

    /**
     * Create a new length-aware paginator instance.
     */
    protected function paginator(Collection $items, int $total, int $perPage, ?int $currentPage, string $pageName): LengthAwarePaginator
    {
        return new LengthAwarePaginator($items, $total, $perPage, $currentPage, $pageName);
    }

    /**
     * Get or set the total amount.
     */
    public function total(?int $total = null): ?int
    {
        if (is_null($total)) {
            return $this->total;
        }

        return $this->total = $total;
    }
}
