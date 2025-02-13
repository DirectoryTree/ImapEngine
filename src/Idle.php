<?php

namespace DirectoryTree\ImapEngine;

use Carbon\Carbon;
use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
use DirectoryTree\ImapEngine\Exceptions\Exception;
use DirectoryTree\ImapEngine\Exceptions\ImapConnectionClosedException;
use DirectoryTree\ImapEngine\Exceptions\ImapConnectionTimedOutException;
use Generator;

class Idle
{
    /**
     * Constructor.
     */
    public function __construct(
        protected Mailbox $mailbox,
        protected string $folder,
        protected int $timeout,
    ) {}

    /**
     * Destructor.
     */
    public function __destruct()
    {
        $this->disconnect();
    }

    /**
     * Await new messages on the connection.
     */
    public function await(callable $callback): void
    {
        $this->connect();

        // Loop indefinitely, restarting IDLE sessions as needed.
        while (true) {
            $ttl = $this->getNextTimeout();

            try {
                $this->listen($callback, $ttl);
            } catch (ImapConnectionTimedOutException) {
                $this->restart();
            } catch (ImapConnectionClosedException) {
                $this->reconnect();
            }
        }
    }

    /**
     * Start listening for new messages using the idle() generator.
     */
    protected function listen(callable $callback, Carbon $ttl): void
    {
        // Iterate over responses yielded by the idle generator.
        foreach ($this->idle() as $response) {
            if (! $response instanceof UntaggedResponse) {
                continue;
            }

            if ($response->tokenAt(2)?->is('EXISTS')) {
                $msgn = (int) $response->tokenAt(1)->value;

                $callback($msgn);

                $ttl = $this->getNextTimeout();
            }

            // If we've been idle too long, break out to restart the session.
            if (Carbon::now()->greaterThanOrEqualTo($ttl)) {
                $this->restart();

                break;
            }
        }
    }

    /**
     * Get the folder to idle.
     */
    protected function folder(): Folder
    {
        return $this->mailbox->folders()->find($this->folder);
    }

    /**
     * Issue a done command and restart the idle session.
     */
    protected function restart(): void
    {
        try {
            // Send DONE to terminate the current IDLE session gracefully.
            $this->done();
        } catch (Exception) {
            $this->reconnect();
        }
    }

    /**
     * Reconnect the client and restart the idle session.
     */
    protected function reconnect(): void
    {
        $this->mailbox->disconnect();

        $this->connect();
    }

    /**
     * Connect the client and select the folder to idle.
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
            // Attempt to terminate IDLE gracefully.
            $this->done();
        } catch (Exception) {
            // Do nothing.
        }

        $this->mailbox->disconnect();
    }

    /**
     * End the current IDLE session.
     */
    protected function done(): void
    {
        $this->mailbox->connection()->done();
    }

    /**
     * Begin a new IDLE session as a generator.
     */
    protected function idle(): Generator
    {
        yield from $this->mailbox->connection()->idle($this->timeout);
    }

    /**
     * Get the next timeout as a Carbon instance.
     */
    protected function getNextTimeout(): Carbon
    {
        return Carbon::now()->addSeconds($this->timeout);
    }
}
