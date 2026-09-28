<?php

namespace DirectoryTree\ImapEngine;

use Closure;
use DirectoryTree\ImapEngine\Exceptions\ImapConnectionClosedException;
use DirectoryTree\ImapEngine\Exceptions\ImapConnectionTimedOutException;
use DirectoryTree\ImapEngine\Idle\EventFactory;
use DirectoryTree\ImapEngine\Idle\Events\EventInterface;
use DirectoryTree\ImapEngine\Idle\Events\FolderSelected;
use Generator;

class Idle
{
    /**
     * Constructor.
     */
    public function __construct(
        protected Mailbox $mailbox,
        protected string $folder,
        protected Closure|int $timeout,
        protected array $options = [],
    ) {}

    /**
     * Await mailbox events until the callback returns false or the timeout stops renewal.
     *
     * Callback exceptions propagate to the caller.
     *
     * @param  callable(EventInterface): mixed  $callback
     */
    public function await(callable $callback): void
    {
        try {
            foreach ($this->events() as $event) {
                if ($callback($event) === false) {
                    break;
                }
            }
        } finally {
            $this->disconnect();
        }
    }

    /**
     * Yield folder selection and server response events without fetching messages.
     *
     * @return Generator<int, EventInterface>
     */
    protected function events(): Generator
    {
        $folder = null;

        while (is_numeric($seconds = value($this->timeout)) && $seconds > 0) {
            $folder ??= $this->mailbox->folders()->findOrFail($this->folder);

            if (! $this->mailbox->selected($folder)) {
                yield new FolderSelected($this->folder, $folder->select(true, ...$this->options));
            }

            $seconds = max((int) $seconds, 1);

            $session = $this->mailbox->connection()->idle();

            try {
                foreach ($session->responses($seconds) as $response) {
                    yield EventFactory::fromResponse($this->folder, $response);
                }
            } catch (ImapConnectionTimedOutException) {
                // End the timed-out IDLE command before starting another.
            } catch (ImapConnectionClosedException) {
                $this->disconnect();

                continue;
            }

            try {
                foreach ($session->finish($this->mailbox->config('timeout')) as $response) {
                    yield EventFactory::fromResponse($this->folder, $response);
                }
            } catch (ImapConnectionClosedException|ImapConnectionTimedOutException) {
                $this->disconnect();
            }
        }
    }

    /**
     * Close the dedicated socket without sending commands during IDLE.
     */
    protected function disconnect(): void
    {
        if ($this->mailbox->connected()) {
            $this->mailbox->connection()->disconnect();
        }

        $this->mailbox->disconnect();
    }
}
