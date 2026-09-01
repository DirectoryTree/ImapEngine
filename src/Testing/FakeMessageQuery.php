<?php

namespace DirectoryTree\ImapEngine\Testing;

use BackedEnum;
use DateTimeInterface;
use DirectoryTree\ImapEngine\AppendResult;
use DirectoryTree\ImapEngine\Collections\MessageCollection;
use DirectoryTree\ImapEngine\Connection\ImapQueryBuilder;
use DirectoryTree\ImapEngine\Enums\ImapFetchIdentifier;
use DirectoryTree\ImapEngine\Enums\ImapSortKey;
use DirectoryTree\ImapEngine\Enums\SortDirection;
use DirectoryTree\ImapEngine\MessageInterface;
use DirectoryTree\ImapEngine\MessageQueryInterface;
use DirectoryTree\ImapEngine\Pagination\LengthAwarePaginator;
use DirectoryTree\ImapEngine\QueriesMessages;
use DirectoryTree\ImapEngine\UidOrder;

class FakeMessageQuery implements MessageQueryInterface
{
    use QueriesMessages;

    /**
     * Constructor.
     */
    public function __construct(
        protected FakeFolder $folder,
        protected ImapQueryBuilder $query = new ImapQueryBuilder
    ) {
        $this->ordering = new UidOrder(SortDirection::Descending);
    }

    /**
     * {@inheritDoc}
     */
    public function get(): MessageCollection
    {
        return $this->applyOrdering(new MessageCollection(
            $this->folder->getMessages()
        ));
    }

    /**
     * {@inheritDoc}
     */
    public function count(): int
    {
        return count(
            $this->folder->getMessages()
        );
    }

    /**
     * {@inheritDoc}
     */
    public function first(): ?MessageInterface
    {
        return $this->get()->first();
    }

    /**
     * {@inheritDoc}
     */
    public function firstOrFail(): MessageInterface
    {
        return $this->get()->firstOrFail();
    }

    /**
     * {@inheritDoc}
     */
    public function append(string $message, mixed $flags = null, ?DateTimeInterface $date = null): AppendResult
    {
        $uid = (int) collect($this->folder->getMessages())->max(
            fn (FakeMessage $message) => $message->uid()
        ) + 1;

        $this->folder->addMessage(
            new FakeMessage($uid, $flags === null ? [] : $flags, $message)
        );

        return new AppendResult(uid: $uid);
    }

    /**
     * Apply the selected ordering strategy.
     */
    protected function applyOrdering(MessageCollection $messages): MessageCollection
    {
        if ($this->ordering instanceof UidOrder) {
            return $messages->sortBy(
                fn (MessageInterface $message) => $message->uid(),
                descending: $this->ordering->direction === SortDirection::Descending,
            )->values();
        }

        foreach (array_reverse($this->ordering->criteria) as $criterion) {
            $messages = $messages->sortBy(
                fn (MessageInterface $message) => $this->sortValue($message, $criterion->key),
                descending: $criterion->direction === SortDirection::Descending,
            );
        }

        return $messages->values();
    }

    /**
     * Get a message's value for the given sort key.
     */
    protected function sortValue(MessageInterface $message, ImapSortKey $key): mixed
    {
        return match ($key) {
            ImapSortKey::Cc => head($message->cc())?->email() ?? '',
            ImapSortKey::To => head($message->to())?->email() ?? '',
            ImapSortKey::Date => $message->date()?->getTimestamp() ?? 0,
            ImapSortKey::From => $message->from()?->email() ?? '',
            ImapSortKey::Size => $message->size(),
            ImapSortKey::Arrival => $message->uid(),
            ImapSortKey::Subject => $message->subject() ?? '',
        };
    }

    /**
     * {@inheritDoc}
     */
    public function each(callable $callback, int $chunkSize = 10, int $startChunk = 1): void
    {
        $this->chunk(function (MessageCollection $messages) use ($callback) {
            foreach ($messages as $key => $message) {
                if ($callback($message, $key) === false) {
                    return false;
                }
            }
        }, $chunkSize, $startChunk);
    }

    /**
     * {@inheritDoc}
     */
    public function chunk(callable $callback, int $chunkSize = 10, int $startChunk = 1): void
    {
        $page = $startChunk;

        foreach ($this->get()->chunk($chunkSize) as $chunk) {
            if ($page < $startChunk) {
                $page++;

                continue;
            }

            // If the callback returns false, break out.
            if ($callback($chunk, $page) === false) {
                break;
            }

            $page++;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function paginate(int $perPage = 5, $page = null, string $pageName = 'page'): LengthAwarePaginator
    {
        return $this->get()->paginate($perPage, $page, $pageName);
    }

    /**
     * {@inheritDoc}
     */
    public function findOrFail(int $id, ImapFetchIdentifier $identifier = ImapFetchIdentifier::Uid): MessageInterface
    {
        return $this->get()->findOrFail($id);
    }

    /**
     * {@inheritDoc}
     */
    public function find(int $id, ImapFetchIdentifier $identifier = ImapFetchIdentifier::Uid): ?MessageInterface
    {
        return $this->get()->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function destroy(array|int $uids, bool $expunge = false): void
    {
        $messages = $this->get()->keyBy(
            fn (MessageInterface $message) => $message->uid()
        );

        foreach ((array) $uids as $uid) {
            $messages->pull($uid);
        }

        $this->folder->setMessages(
            $messages->values()->all()
        );
    }

    /**
     * {@inheritDoc}
     */
    public function flag(BackedEnum|string $flag, string $operation, bool $expunge = false): int
    {
        return count($this->folder->getMessages());
    }

    /**
     * {@inheritDoc}
     */
    public function markRead(): int
    {
        return count($this->folder->getMessages());
    }

    /**
     * {@inheritDoc}
     */
    public function markUnread(): int
    {
        return count($this->folder->getMessages());
    }

    /**
     * {@inheritDoc}
     */
    public function markFlagged(): int
    {
        return count($this->folder->getMessages());
    }

    /**
     * {@inheritDoc}
     */
    public function unmarkFlagged(): int
    {
        return count($this->folder->getMessages());
    }

    /**
     * {@inheritDoc}
     */
    public function delete(bool $expunge = false): int
    {
        $count = count($this->folder->getMessages());

        $this->folder->setMessages([]);

        return $count;
    }

    /**
     * {@inheritDoc}
     */
    public function move(string $folder, bool $expunge = false): int
    {
        return count($this->folder->getMessages());
    }

    /**
     * {@inheritDoc}
     */
    public function copy(string $folder): int
    {
        return count($this->folder->getMessages());
    }
}
