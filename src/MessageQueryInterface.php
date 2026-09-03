<?php

namespace DirectoryTree\ImapEngine;

use BackedEnum;
use DateTimeInterface;
use DirectoryTree\ImapEngine\Collections\MessageCollection;
use DirectoryTree\ImapEngine\Connection\ImapQueryBuilder;
use DirectoryTree\ImapEngine\Enums\ImapIdentifier;
use DirectoryTree\ImapEngine\Enums\ImapSortKey;
use DirectoryTree\ImapEngine\Enums\SortDirection;
use DirectoryTree\ImapEngine\MessageData\FetchItem;
use DirectoryTree\ImapEngine\Pagination\LengthAwarePaginator;

/**
 * @mixin ImapQueryBuilder
 */
interface MessageQueryInterface
{
    /**
     * Set the limit and page for the current query.
     */
    public function limit(int $limit, int $page = 1): MessageQueryInterface;

    /**
     * Get the set fetch limit.
     */
    public function getLimit(): ?int;

    /**
     * Set the fetch limit.
     */
    public function setLimit(int $limit): MessageQueryInterface;

    /**
     * Get the set page.
     */
    public function getPage(): int;

    /**
     * Set the page.
     */
    public function setPage(int $page): MessageQueryInterface;

    /**
     * Add items to the message FETCH request.
     */
    public function with(FetchItem ...$items): static;

    /**
     * Remove items from the message FETCH request.
     */
    public function without(FetchItem ...$items): static;

    /**
     * Replace the items in the message FETCH request.
     */
    public function only(FetchItem ...$items): static;

    /**
     * Order messages locally by UID, replacing any server-side sort criteria.
     */
    public function orderByUid(
        SortDirection|string $direction = SortDirection::Ascending,
    ): static;

    /**
     * Add a server-side sort criterion using RFC 5256.
     *
     * Subsequent calls are used as tie-breakers in the order they are added.
     */
    public function sortBy(
        ImapSortKey|string $key,
        SortDirection|string $direction = SortDirection::Ascending,
    ): static;

    /**
     * Count all available messages matching the current search criteria.
     */
    public function count(): int;

    /**
     * Get the first message in the resulting collection.
     */
    public function first(): ?MessageInterface;

    /**
     * Get the first message in the resulting collection or throw an exception.
     */
    public function firstOrFail(): MessageInterface;

    /**
     * Get the messages matching the current query.
     */
    public function get(): MessageCollection;

    /**
     * Get messages changed after the given modification sequence.
     *
     * Requesting vanished messages requires enabling QRESYNC before selecting a folder.
     */
    public function changesSince(int $modSequence, array|int $uids, bool $vanished = false): FetchResult;

    /**
     * Append a new message to the folder.
     */
    public function append(string $message, mixed $flags = null, ?DateTimeInterface $date = null): AppendResult;

    /**
     * Execute a callback over each message via a chunked query.
     */
    public function each(callable $callback, int $chunkSize = 10, int $startChunk = 1): void;

    /**
     * Execute a callback over each chunk of messages.
     */
    public function chunk(callable $callback, int $chunkSize = 10, int $startChunk = 1): void;

    /**
     * Paginate the current query.
     */
    public function paginate(int $perPage = 5, $page = null, string $pageName = 'page'): LengthAwarePaginator;

    /**
     * Find a message by the given identifier type or throw an exception.
     */
    public function findOrFail(int $id, ImapIdentifier $identifier = ImapIdentifier::Uid): MessageInterface;

    /**
     * Find a message by the given identifier type.
     */
    public function find(int $id, ImapIdentifier $identifier = ImapIdentifier::Uid): ?MessageInterface;

    /**
     * Destroy the given messages.
     */
    public function destroy(array|int $uids, bool $expunge = false): void;

    /**
     * Add or remove a flag from all messages matching the current query.
     *
     * @param  string  $operation  '+'|'-'
     * @return int The number of messages affected.
     */
    public function flag(BackedEnum|string $flag, string $operation, bool $expunge = false): int;

    /**
     * Mark all messages matching the current query as read.
     *
     * @return int The number of messages affected.
     */
    public function markRead(): int;

    /**
     * Mark all messages matching the current query as unread.
     *
     * @return int The number of messages affected.
     */
    public function markUnread(): int;

    /**
     * Mark all messages matching the current query as flagged.
     *
     * @return int The number of messages affected.
     */
    public function markFlagged(): int;

    /**
     * Unmark all messages matching the current query as flagged.
     *
     * @return int The number of messages affected.
     */
    public function unmarkFlagged(): int;

    /**
     * Delete all messages matching the current query.
     *
     * @return int The number of messages affected.
     */
    public function delete(bool $expunge = false): int;

    /**
     * Move all messages matching the current query to the given folder.
     *
     * @return int The number of messages affected.
     */
    public function move(string $folder, bool $expunge = false): int;

    /**
     * Copy all messages matching the current query to the given folder.
     *
     * @return int The number of messages affected.
     */
    public function copy(string $folder): int;
}
