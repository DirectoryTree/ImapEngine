<?php

namespace DirectoryTree\ImapEngine;

use Closure;
use DirectoryTree\ImapEngine\Exceptions\Exception;
use DirectoryTree\ImapEngine\Exceptions\ImapConnectionClosedException;
use DirectoryTree\ImapEngine\Selection\Result;
use Generator;

class Poll
{
    /**
     * The first UID that has not yet been delivered.
     */
    protected ?int $nextUid = null;

    /**
     * The polling connection's latest folder selection.
     */
    protected ?Result $selection = null;

    /**
     * Constructor.
     */
    public function __construct(
        protected Mailbox $mailbox,
        protected string $folder,
        protected Closure|int $frequency,
    ) {}

    /**
     * Poll for new messages at a given frequency.
     */
    public function start(callable $callback, callable $query): void
    {
        try {
            foreach ($this->messages($query) as $message) {
                if ($callback($message) === false) {
                    break;
                }
            }
        } finally {
            $this->disconnect();
        }
    }

    /**
     * Yield arrivals, reconnecting when the polling connection is lost.
     *
     * @return Generator<int, MessageInterface>
     */
    protected function messages(callable $query): Generator
    {
        $this->connect();

        while ($frequency = $this->getNextFrequency()) {
            try {
                yield from $this->check($query);
            } catch (ImapConnectionClosedException) {
                $this->reconnect();
            }

            sleep($frequency);
        }
    }

    /**
     * Check for new messages since the last delivered UID.
     *
     * @return Generator<int, MessageInterface>
     */
    protected function check(callable $query): Generator
    {
        $folder = $this->folder();

        $this->select($folder);

        $messages = $query($folder->messages()->with(MessageData::flags()))
            ->uid($this->nextUid.':*')
            ->orderByUid()
            ->cursor();

        foreach ($messages as $message) {
            // Avoid processing the same message twice on subsequent polls.
            // Some IMAP servers will always return the last seen UID in
            // the search results regardless of given UID search range.
            if ($message->uid() < $this->nextUid) {
                continue;
            }

            yield $message;

            $this->nextUid = $message->uid() + 1;
        }
    }

    /**
     * Get the folder to poll.
     */
    protected function folder(): FolderInterface
    {
        return $this->mailbox->folders()->findOrFail($this->folder);
    }

    /**
     * Reconnect the client and restart the poll session.
     */
    protected function reconnect(): void
    {
        $this->mailbox->disconnect();

        $this->connect();
    }

    /**
     * Connect the client and select the folder to poll.
     */
    protected function connect(): void
    {
        $this->mailbox->connect();

        $this->select($this->folder());
    }

    /**
     * Select the folder and reset the arrival cursor when UID validity changes.
     */
    protected function select(FolderInterface $folder): void
    {
        $selection = $folder->select(true);

        if ($this->selection === null || $this->selection->uidValidity() !== $selection->uidValidity()) {
            $this->nextUid = $selection->uidNext() ?? $this->getNextUid($folder);
        }

        $this->selection = $selection;
    }

    /**
     * Determine the next UID from the folder's existing messages.
     */
    protected function getNextUid(FolderInterface $folder): int
    {
        return ($folder->messages()->orderByUid('desc')->first()?->uid() ?? 0) + 1;
    }

    /**
     * Disconnect the client.
     */
    protected function disconnect(): void
    {
        try {
            $this->mailbox->disconnect();
        } catch (Exception) {
            // Do nothing.
        }
    }

    /**
     * Get the next frequency in seconds.
     */
    protected function getNextFrequency(): int|false
    {
        if (is_numeric($seconds = value($this->frequency))) {
            return abs((int) $seconds);
        }

        return false;
    }
}
