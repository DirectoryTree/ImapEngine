<?php

namespace DirectoryTree\ImapEngine;

use Carbon\Carbon;
use DirectoryTree\ImapEngine\Connection\Response;
use DirectoryTree\ImapEngine\Exceptions\ConnectionClosedException;
use DirectoryTree\ImapEngine\Exceptions\ConnectionTimedOutException;
use DirectoryTree\ImapEngine\Exceptions\RuntimeException;

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
        $this->mailbox->disconnect();
    }

    /**
     * Await new messages on the connection.
     */
    public function await(callable $callback): void
    {
        $this->connect();

        $this->idle();

        $ttl = $this->getNextTimeout();

        $sequence = $this->mailbox->config('options.sequence', Imap::ST_MSGN);

        while (true) {
            try {
                $line = $this->getNextLine();
            } catch (ConnectionTimedOutException) {
                $this->reidle();

                $ttl = $this->getNextTimeout();

                continue;
            } catch (ConnectionClosedException) {
                $this->reconnect();

                $ttl = $this->getNextTimeout();

                continue;
            }

            if (($pos = strpos($line, 'EXISTS')) !== false) {
                $msgn = (int) substr($line, 2, $pos - 2);

                $callback($msgn, $sequence);

                $ttl = $this->getNextTimeout();
            }

            if (! Carbon::now()->greaterThanOrEqualTo($ttl)) {
                continue;
            }

            try {
                // If we've been idle too long, we'll send a DONE and re-IDLE.
                // This will keep the server from killing the connection.
                // Some Servers require this to avoid disconnection.
                $this->done();
            } catch (RuntimeException) {
                // If done fails, we're likely already disconnected.
                // We'll attempt to reconnect and restart the IDLE.
                $this->reconnect();
            }

            $this->idle();

            $ttl = $this->getNextTimeout();
        }
    }

    /**
     * Reconnect the client and restart IDLE.
     */
    protected function reconnect(): void
    {
        $this->mailbox->disconnect();

        $this->connect();
    }

    /**
     * Connect the client and begin IDLE.
     */
    protected function connect(): void
    {
        $this->mailbox->connect();

        $this->mailbox->select($this->folder(), true);

        $this->mailbox->connection()->setStreamTimeout($this->timeout);
    }

    /**
     * Get the folder to idle.
     */
    protected function folder(): Folder
    {
        return $this->mailbox->folders()->findByPath($this->folder);
    }

    /**
     * End the current IDLE session and start a new one.
     */
    protected function reidle(): void
    {
        try {
            $this->done();
        } catch (RuntimeException) {
            $this->reconnect();
        }

        $this->idle();
    }

    /**
     * End the current IDLE session.
     */
    protected function done(): void
    {
        $this->mailbox->connection()->done();
    }

    /**
     * Being a new IDLE session.
     */
    protected function idle(): void
    {
        $this->mailbox->connection()->idle();
    }

    /**
     * Get the next line from the connection.
     *
     * @throws ConnectionTimedOutException|ConnectionClosedException
     */
    protected function getNextLine(): string
    {
        return $this->mailbox->connection()->nextLine(Response::empty());
    }

    /**
     * Get the next timeout.
     */
    protected function getNextTimeout(): Carbon
    {
        return Carbon::now()->addSeconds($this->timeout);
    }
}
