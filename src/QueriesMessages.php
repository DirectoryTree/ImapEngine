<?php

namespace DirectoryTree\ImapEngine;

use DirectoryTree\ImapEngine\Connection\ImapQueryBuilder;
use DirectoryTree\ImapEngine\Enums\ImapSortKey;
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
     *
     * @var 'asc'|'desc'
     */
    protected string $fetchOrder = 'desc';

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
     *
     * @var 'asc'|'desc'
     */
    protected string $sortDirection = 'asc';

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
    public function setFetchOrder(string $fetchOrder): MessageQueryInterface
    {
        $fetchOrder = strtolower($fetchOrder);

        if (in_array($fetchOrder, ['asc', 'desc'])) {
            $this->fetchOrder = $fetchOrder;
        }

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getFetchOrder(): string
    {
        return $this->fetchOrder;
    }

    /**
     * {@inheritDoc}
     */
    public function setFetchOrderAsc(): MessageQueryInterface
    {
        return $this->setFetchOrder('asc');
    }

    /**
     * {@inheritDoc}
     */
    public function setFetchOrderDesc(): MessageQueryInterface
    {
        return $this->setFetchOrder('desc');
    }

    /**
     * {@inheritDoc}
     */
    public function oldest(): MessageQueryInterface
    {
        return $this->setFetchOrder('asc');
    }

    /**
     * {@inheritDoc}
     */
    public function newest(): MessageQueryInterface
    {
        return $this->setFetchOrder('desc');
    }

    /**
     * {@inheritDoc}
     */
    public function setSortKey(ImapSortKey|string|null $key): MessageQueryInterface
    {
        if (is_string($key)) {
            $key = ImapSortKey::from(strtoupper($key));
        }

        $this->sortKey = $key;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getSortKey(): ?ImapSortKey
    {
        return $this->sortKey;
    }

    /**
     * {@inheritDoc}
     */
    public function setSortDirection(string $direction): MessageQueryInterface
    {
        $direction = strtolower($direction);

        if (in_array($direction, ['asc', 'desc'])) {
            $this->sortDirection = $direction;
        }

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getSortDirection(): string
    {
        return $this->sortDirection;
    }

    /**
     * {@inheritDoc}
     */
    public function sortBy(ImapSortKey|string $key, string $direction = 'asc'): MessageQueryInterface
    {
        return $this->setSortKey($key)->setSortDirection($direction);
    }

    /**
     * {@inheritDoc}
     */
    public function sortByDesc(ImapSortKey|string $key): MessageQueryInterface
    {
        return $this->sortBy($key, 'desc');
    }
}
