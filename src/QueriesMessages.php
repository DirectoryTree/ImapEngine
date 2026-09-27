<?php

namespace DirectoryTree\ImapEngine;

use DirectoryTree\ImapEngine\Connection\ImapQueryBuilder;
use DirectoryTree\ImapEngine\Enums\ImapSortKey;
use DirectoryTree\ImapEngine\Enums\SortDirection;
use DirectoryTree\ImapEngine\MessageData\FetchItemInterface;
use DirectoryTree\ImapEngine\Support\ForwardsCalls;
use DirectoryTree\ImapEngine\Support\Str;
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
     * @var array<string, FetchItemInterface>
     */
    protected array $fetchItems = [];

    /**
     * The message ordering strategy.
     */
    protected UidOrder|ImapSort $ordering;

    /**
     * The methods that should be returned from query builder.
     */
    protected array $passthru = ['toimap', 'isempty'];

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
    public function with(FetchItemInterface ...$items): static
    {
        foreach ($items as $item) {
            $this->fetchItems[$item->key()] = $item;
        }

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function without(FetchItemInterface ...$items): static
    {
        foreach ($items as $item) {
            unset($this->fetchItems[$item->key()]);
        }

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function only(FetchItemInterface ...$items): static
    {
        $this->fetchItems = [];

        return $this->with(...$items);
    }

    /**
     * {@inheritDoc}
     */
    public function orderByUid(
        SortDirection|string $direction = SortDirection::Ascending,
    ): static {
        $this->ordering = new UidOrder(
            SortDirection::from(strtolower(Str::enum($direction))),
        );

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function sortBy(
        ImapSortKey|string $key,
        SortDirection|string $direction = SortDirection::Ascending,
    ): static {
        $key = ImapSortKey::from(strtoupper(Str::enum($key)));

        $direction = SortDirection::from(strtolower(Str::enum($direction)));

        $criterion = new SortCriterion($key, $direction);

        if ($this->ordering instanceof ImapSort) {
            $this->ordering->add($criterion);
        } else {
            $this->ordering = new ImapSort($criterion);
        }

        return $this;
    }
}
