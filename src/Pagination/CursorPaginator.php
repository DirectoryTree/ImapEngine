<?php

namespace DirectoryTree\ImapEngine\Pagination;

use Illuminate\Support\Collection;

/**
 * @template TValue
 */
class CursorPaginator
{
    /**
     * Create a page with its continuation determined before fetching messages.
     *
     * @param  Collection<int, TValue>  $items
     */
    public function __construct(
        protected Collection $items,
        protected ?Cursor $nextCursor = null,
    ) {}

    /**
     * Get the items on this page.
     *
     * @return Collection<int, TValue>
     */
    public function items(): Collection
    {
        return $this->items;
    }

    /**
     * Get the cursor for the next page.
     */
    public function nextCursor(): ?Cursor
    {
        return $this->nextCursor;
    }

    /**
     * Determine whether more pages remain, even if this page is empty.
     */
    public function hasMorePages(): bool
    {
        return $this->nextCursor !== null;
    }
}
