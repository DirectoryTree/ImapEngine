<?php

namespace DirectoryTree\ImapEngine;

use DirectoryTree\ImapEngine\Connection\ImapQueryBuilder;
use DirectoryTree\ImapEngine\Enums\ImapSortKey;
use DirectoryTree\ImapEngine\Enums\SortDirection;
use DirectoryTree\ImapEngine\MessageData\FetchItem;
use DirectoryTree\ImapEngine\Support\ForwardsCalls;
use Illuminate\Support\Traits\Conditionable;

trait QueriesMessages
{
    use Conditionable, ForwardsCalls;

    /**
     * The query builder instance.
     */
    protected ImapQueryBuilder $query;

    /**
     * The current page.
     */
    protected int $page = 1;

    /**
     * The fetch limit.
     */
    protected ?int $limit = null;

    /**
     * The items to include in message FETCH requests.
     *
     * @var array<string, FetchItem>
     */
    protected array $fetchItems = [];

    /**
     * The fetch order.
     */
    protected SortDirection $fetchOrder = SortDirection::Descending;

    /**
     * The methods that should be returned from query builder.
     */
    protected array $passthru = ['toimap', 'isempty'];

    /**
     * The sort key for server-side sorting (RFC 5256).
     */
    protected ?ImapSortKey $sortKey = null;

    /**
     * The sort direction for server-side sorting.
     */
    protected SortDirection $sortDirection = SortDirection::Ascending;

    /**
     * Handle dynamic method calls into the query builder.
     */
    public function __call(string $method, array $parameters): mixed
    {
        if (in_array(strtolower($method), $this->passthru)) {
            return $this->query->{$method}(...$parameters);
        }

        $this->forwardCallTo($this->query, $method, $parameters);

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function limit(int $limit, int $page = 1): MessageQueryInterface
    {
        if ($page >= 1) {
            $this->page = $page;
        }

        $this->limit = $limit;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getLimit(): ?int
    {
        return $this->limit;
    }

    /**
     * {@inheritDoc}
     */
    public function setLimit(int $limit): MessageQueryInterface
    {
        $this->limit = max($limit, 1);

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getPage(): int
    {
        return $this->page;
    }

    /**
     * {@inheritDoc}
     */
    public function setPage(int $page): MessageQueryInterface
    {
        $this->page = $page;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function with(FetchItem ...$items): static
    {
        foreach ($items as $item) {
            $this->fetchItems[$item->key()] = $item;
        }

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function without(FetchItem ...$items): static
    {
        foreach ($items as $item) {
            unset($this->fetchItems[$item->key()]);
        }

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function only(FetchItem ...$items): static
    {
        $this->fetchItems = [];

        return $this->with(...$items);
    }

    /**
     * {@inheritDoc}
     */
    public function oldest(): static
    {
        $this->fetchOrder = SortDirection::Ascending;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function newest(): static
    {
        $this->fetchOrder = SortDirection::Descending;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function sortBy(
        ImapSortKey|string $key,
        SortDirection|string $direction = SortDirection::Ascending,
    ): static {
        $this->sortKey = is_string($key)
            ? ImapSortKey::from(strtoupper($key))
            : $key;

        $this->sortDirection = is_string($direction)
            ? SortDirection::from(strtolower($direction))
            : $direction;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function sortByDesc(ImapSortKey|string $key): static
    {
        return $this->sortBy($key, SortDirection::Descending);
    }
}
