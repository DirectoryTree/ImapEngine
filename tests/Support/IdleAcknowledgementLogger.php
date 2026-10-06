<?php

namespace Tests\Support;

use Closure;
use DirectoryTree\ImapEngine\Connection\Loggers\LoggerInterface;

class IdleAcknowledgementLogger implements LoggerInterface
{
    /**
     * Whether the callback has been invoked for an IDLE acknowledgement.
     */
    public bool $acknowledged = false;

    /**
     * The IDLE command awaiting a continuation response.
     */
    protected ?string $tag = null;

    /**
     * Create a logger that invokes the callback once IDLE is acknowledged.
     */
    public function __construct(protected Closure $callback) {}

    /**
     * {@inheritDoc}
     */
    public function sent(string $message): void
    {
        if (preg_match('/^(\S+) IDLE$/i', $message, $matches)) {
            $this->tag = $matches[1];
        }
    }

    /**
     * {@inheritDoc}
     */
    public function received(string $message): void
    {
        if ($this->tag === null || $this->acknowledged) {
            return;
        }

        if (str_starts_with($message, $this->tag.' ')) {
            $this->tag = null;
        } elseif (str_starts_with($message, '+')) {
            $this->tag = null;
            $this->acknowledged = true;

            ($this->callback)();
        }
    }
}
