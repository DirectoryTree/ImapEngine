<?php

namespace DirectoryTree\ImapEngine;

use Carbon\Carbon;
use DirectoryTree\ImapEngine\Connection\Responses\Response;
use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
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
        $this->disconnect();
    }

    /**
     * Await new messages on the connection.
     */
    public function await(callable $callback): void
    {
        $this->connect();

        $this->idle();

        $ttl = $this->getNextTimeout();

        $sequence = $this->mailbox->config('options.sequence', Imap::SEQUENCE_TYPE_MSG_NUMBER);

        try {
            $this->listen($callback, $sequence, $ttl);
        } catch (ConnectionTimedOutException) {
            $this->reidle();

            $ttl = $this->getNextTimeout();

            $this->listen($callback, $sequence, $ttl);
        } catch (ConnectionClosedException) {
            $this->reconnect();

            $ttl = $this->getNextTimeout();

            $this->listen($callback, $sequence, $ttl);
        }
    }

    /**
     * Start listening for new messages.
     */
    protected function listen(callable $callback, int $sequence, Carbon $ttl): void
    {
        while ($response = $this->getNextReply()) {
            if (! $response instanceof UntaggedResponse) {
                continue;
            }

            if ($response->tokenAt(2)?->is('EXISTS')) {
                $msgn = (int) $response->tokenAt(1)->value;

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
     * Connect the client and begin IDLE.
     */
    protected function connect(): void
    {
        $this->mailbox->connect();

        $this->mailbox->select($this->folder(), true);

        $this->mailbox->connection()->setStreamTimeout($this->timeout);
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
     * Disconnect the client.
     */
    protected function disconnect(): void
    {
        $this->done();

        $this->mailbox->disconnect();
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
     * Get the next reply from the connection.
     */
    protected function getNextReply(): Response
    {
        return $this->mailbox->connection()->nextReply();
    }

    /**
     * Get the next timeout.
     */
    protected function getNextTimeout(): Carbon
    {
        return Carbon::now()->addSeconds($this->timeout);
    }
}
