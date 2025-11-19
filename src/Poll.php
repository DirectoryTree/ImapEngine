<?php

namespace DirectoryTree\ImapEngine;

use Closure;
use DirectoryTree\ImapEngine\Exceptions\Exception;
use DirectoryTree\ImapEngine\Exceptions\ImapConnectionClosedException;

class Poll
{
    /**
     * The last seen message UID.
     */
    protected ?int $lastSeenUid = null;

    /**
     * Constructor.
     */
    public function __construct(
        protected Mailbox $mailbox,
        protected string $folder,
        protected Closure|int $frequency,
    ) {}

    /**
     * Destructor.
     */
    public function __destruct()
    {
        $this->disconnect();
    }

    /**
     * Poll for new messages at a given frequency.
     */
    public function start(callable $callback): void
    {
        $this->connect();

        while ($frequency = $this->getNextFrequency()) {
            try {
                $this->check($callback);
            } catch (ImapConnectionClosedException) {
                $this->reconnect();
            }

            sleep($frequency);
        }
    }

    /**
     * Check for new messages since the last seen UID.
     */
    protected function check(callable $callback): void
    {
        $folder = $this->folder();

        $query = $folder->messages();

        // If we have a last seen UID, search for messages after it.
        if ($this->lastSeenUid !== null) {
            $query->uid($this->lastSeenUid + 1, INF);
        }

        $messages = $query->get();

        foreach ($messages as $message) {
            $callback($message);

            $this->lastSeenUid = $message->uid();
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

        $this->mailbox->select($this->folder(), true);
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
