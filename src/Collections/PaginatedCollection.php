<?php

namespace DirectoryTree\ImapEngine\Collections;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;

class PaginatedCollection extends Collection
{
    /**
     * Number of total entries.
     */
    protected int $total = 0;

    /**
     * Paginate the current collection.
     */
    public function paginate(int $perPage = 15, ?int $page = null, string $pageName = 'page', bool $prepaginated = false): LengthAwarePaginator
    {
        $page = $page ?: Paginator::resolveCurrentPage($pageName);

        $total = $this->total ?: $this->count();

        $results = ! $prepaginated && $total ? $this->forPage($page, $perPage)->toArray() : $this->all();

        return $this->paginator($results, $total, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]);
    }

    /**
     * Create a new length-aware paginator instance.
     */
    protected function paginator(array $items, int $total, int $perPage, ?int $currentPage, array $options): LengthAwarePaginator
    {
        return new LengthAwarePaginator($items, $total, $perPage, $currentPage, $options);
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
