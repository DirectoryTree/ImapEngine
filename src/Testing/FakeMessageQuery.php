<?php

namespace DirectoryTree\ImapEngine\Testing;

use BackedEnum;
use DateTimeInterface;
use DirectoryTree\ImapEngine\AppendResult;
use DirectoryTree\ImapEngine\Collections\MessageCollection;
use DirectoryTree\ImapEngine\Connection\ImapQueryBuilder;
use DirectoryTree\ImapEngine\Enums\ImapIdentifier;
use DirectoryTree\ImapEngine\Enums\ImapSortKey;
use DirectoryTree\ImapEngine\Enums\SortDirection;
use DirectoryTree\ImapEngine\Exceptions\ImapCapabilityException;
use DirectoryTree\ImapEngine\FetchedMessageData;
use DirectoryTree\ImapEngine\FetchResult;
use DirectoryTree\ImapEngine\MessageData;
use DirectoryTree\ImapEngine\MessageData\FetchItemInterface;
use DirectoryTree\ImapEngine\MessageInterface;
use DirectoryTree\ImapEngine\MessageQueryInterface;
use DirectoryTree\ImapEngine\Pagination\LengthAwarePaginator;
use DirectoryTree\ImapEngine\QueriesMessages;
use DirectoryTree\ImapEngine\UidOrder;
use DirectoryTree\ImapEngine\Vanished;
use Generator;
use Illuminate\Support\ItemNotFoundException;
use InvalidArgumentException;

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
    public function cursor(int $chunkSize = 10): Generator
    {
        yield from $this->get();
    }

    /**
     * {@inheritDoc}
     */
    public function changesSince(int $modSequence, array|int $uids, bool $vanished = false): FetchResult
    {
        $uids = (array) $uids;

        if ($uids === []) {
            return new FetchResult;
        }

        if ($modSequence < 0) {
            throw new InvalidArgumentException('Invalid IMAP modification sequence.');
        }

        $capability = $vanished ? 'QRESYNC' : 'CONDSTORE';

        $mailbox = $this->folder->mailbox();

        $supported = $mailbox->capabilities()->supports($capability)
            || ($capability === 'CONDSTORE' && $mailbox->capabilities()->supports('QRESYNC'));

        if (! $supported) {
            throw new ImapCapabilityException(
                "Unable to fetch message changes. IMAP server does not support $capability capability."
            );
        }

        if ($vanished && ! $mailbox->capabilities()->enabled('QRESYNC')) {
            throw new ImapCapabilityException(
                'Enable QRESYNC before selecting a folder to request vanished messages.'
            );
        }

        $messages = collect($this->folder->getMessages())
            ->filter(fn (FakeMessage $message) => in_array($message->uid(), $uids, true))
            ->filter(fn (FakeMessage $message) => ($message->modSequence() ?? 0) > $modSequence)
            ->map(fn (FakeMessage $message) => $this->fetchedData($message))
            ->values()
            ->all();

        $vanishedUids = $vanished
            ? $this->folder->vanishedSince($modSequence, $uids)
            : [];

        return new FetchResult(
            $messages,
            $vanishedUids ? [new Vanished($vanishedUids, earlier: true)] : [],
        );
    }

    /**
     * Get the fetched data for the given message.
     */
    protected function fetchedData(FakeMessage $message): FetchedMessageData
    {
        $items = $this->fetchItems ?: [MessageData::flags()];

        $attributes = [
            'UID' => $message->uid(),
            'MODSEQ' => [$message->modSequence()],
        ];

        foreach ($items as $item) {
            $attributes[$item->key()] = $this->fetchValue($message, $item);
        }

        return new FetchedMessageData($attributes);
    }

    /**
     * Get the value of the fetched item for the given message.
     */
    protected function fetchValue(FakeMessage $message, FetchItemInterface $item): mixed
    {
        return match ($item->key()) {
            'FLAGS' => $message->flags(),
            'RFC822.SIZE' => $message->size(),
            'MODSEQ' => [$message->modSequence()],
            'BODYSTRUCTURE' => $message->bodyStructure(),
            default => null,
        };
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
        $uid = $this->folder->nextUid();

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
    public function findOrFail(int $id, ImapIdentifier $identifier = ImapIdentifier::Uid): MessageInterface
    {
        return $this->find($id, $identifier) ?? throw new ItemNotFoundException;
    }

    /**
     * {@inheritDoc}
     */
    public function find(int $id, ImapIdentifier $identifier = ImapIdentifier::Uid): ?MessageInterface
    {
        if ($identifier === ImapIdentifier::Uid) {
            return $this->get()->find($id);
        }

        return collect($this->folder->getMessages())
            ->sortBy(fn (FakeMessage $message) => $message->uid())
            ->values()
            ->get($id - 1);
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
