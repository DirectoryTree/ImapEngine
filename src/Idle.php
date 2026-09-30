<?php

namespace DirectoryTree\ImapEngine;

use DirectoryTree\ImapEngine\Exceptions\ImapConnectionClosedException;
use DirectoryTree\ImapEngine\Idle\Events\EventInterface;
use DirectoryTree\ImapEngine\Idle\Events\FolderSelected;
use DirectoryTree\ImapEngine\Idle\Events\MessagesExist;
use DirectoryTree\ImapEngine\Selection\OptionInterface;
use DirectoryTree\ImapEngine\Selection\Result;
use Generator;

class Idle
{
    /**
     * The first UID that has not yet been delivered.
     */
    protected ?int $nextUid = null;

    /**
     * The watching connection's latest folder selection.
     */
    protected ?Result $selection = null;

    /**
     * Constructor.
     */
    public function __construct(
        protected FolderInterface $folder
    ) {}

    /**
     * Await new messages, optionally customizing their query.
     *
     * @param  callable(MessageInterface): mixed  $callback
     * @param  (callable(MessageQueryInterface): MessageQueryInterface)|null  $query
     */
    public function await(callable $callback, ?callable $query = null, callable|int $timeout = 300, OptionInterface ...$options): void
    {
        $this->folder->events(function (EventInterface $event) use ($callback, $query) {
            if ($event instanceof FolderSelected) {
                $resuming = $this->selection !== null
                    && $this->selection->uidValidity() === $event->selection()->uidValidity();

                if (! $resuming) {
                    $this->nextUid = $event->selection()->uidNext() ?? $this->getNextUid();
                }

                $this->selection = $event->selection();

                if ($resuming) {
                    return $this->deliver($callback, $query);
                }
            }

            if ($event instanceof MessagesExist) {
                return $this->deliver($callback, $query);
            }
        }, $timeout, ...$options);
    }

    /**
     * Retrieve arrivals and deliver them in UID order.
     */
    protected function deliver(callable $callback, ?callable $query): ?bool
    {
        foreach ($this->messages($query) as $message) {
            if ($callback($message) === false) {
                return false;
            }

            $this->nextUid = $message->uid() + 1;
        }

        return null;
    }

    /**
     * Retrieve arrivals, retrying once if the application connection is lost.
     *
     * @return Generator<int, MessageInterface>
     */
    protected function messages(?callable $query): Generator
    {
        try {
            yield from $this->fetch($query);
        } catch (ImapConnectionClosedException) {
            $this->folder->mailbox()->reconnect();

            yield from $this->fetch($query);
        }
    }

    /**
     * Fetch arrivals that belong to the watching connection's UID validity.
     *
     * @return Generator<int, MessageInterface>
     */
    protected function fetch(?callable $query): Generator
    {
        $current = $this->folder->select(true);

        if ($this->selection->uidValidity() !== null && $current->uidValidity() !== $this->selection->uidValidity()) {
            return;
        }

        $messages = $this->folder->messages()->with(MessageData::flags());

        $messages = $query ? $query($messages) : $messages;

        foreach ($messages->uid($this->nextUid.':*')->orderByUid()->cursor() as $message) {
            // Reversed IMAP ranges can include an older UID when no arrivals exist.
            if ($message->uid() >= $this->nextUid) {
                yield $message;
            }
        }
    }

    /**
     * Determine the next UID from the folder's existing messages.
     */
    protected function getNextUid(): int
    {
        return ($this->folder->messages()->orderByUid('desc')->first()?->uid() ?? 0) + 1;
    }
}
