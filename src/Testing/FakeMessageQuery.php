<?php

namespace DirectoryTree\ImapEngine\Testing;

use DirectoryTree\ImapEngine\Collections\MessageCollection;
use DirectoryTree\ImapEngine\Enums\ImapFetchIdentifier;
use DirectoryTree\ImapEngine\MessageInterface;
use DirectoryTree\ImapEngine\MessageQueryInterface;
use DirectoryTree\ImapEngine\Pagination\LengthAwarePaginator;
use DirectoryTree\ImapEngine\QueriesMessages;

class FakeMessageQuery implements MessageQueryInterface
{
    use QueriesMessages;

    /**
     * Constructor.
     */
    public function __construct(
        protected FakeFolder $folder
    ) {}

    /**
     * {@inheritDoc}
     */
    public function get(): MessageCollection
    {
        return new MessageCollection(
            $this->folder->getMessages()
        );
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
    public function append(string $message, mixed $flags = null): int
    {
        $uid = 1;

        if ($lastMessage = $this->get()->last()) {
            $uid = $lastMessage->uid() + 1;
        }

        $this->folder->addMessage(
            new FakeMessage($uid, $flags === null ? [] : $flags, $message)
        );

        return $uid;
    }

    /**
     * {@inheritDoc}
     */
    public function each(callable $callback, int $chunkSize = 10, int $startChunk = 1): void
    {
        $this->get()->each($callback);
    }

    /**
     * {@inheritDoc}
     */
    public function chunk(callable $callback, int $chunkSize = 10, int $startChunk = 1): void
    {
        $this->get()->chunk($chunkSize)->each($callback);
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
}
