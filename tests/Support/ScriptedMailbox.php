<?php

namespace Tests\Support;

use DirectoryTree\ImapEngine\Connection\ConnectionInterface;
use DirectoryTree\ImapEngine\Mailbox;
use Illuminate\Support\Collection;
use LogicException;

class ScriptedMailbox extends Mailbox
{
    /**
     * Sessions shared with cloned mailboxes, in connection order.
     *
     * @var Collection<int, ConnectionInterface>
     */
    protected Collection $connections;

    /**
     * Create a mailbox with the sessions it is allowed to open.
     */
    public function __construct(ConnectionInterface ...$connections)
    {
        parent::__construct();

        $this->connections = new Collection($connections);
    }

    /**
     * Connect using the next scripted session.
     */
    public function connect(?ConnectionInterface $connection = null): void
    {
        if ($this->connected()) {
            return;
        }

        parent::connect($connection ?? $this->connections->shift()
            ?? throw new LogicException('No scripted connections remain.'));
    }
}
