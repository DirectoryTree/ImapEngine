<?php

namespace DirectoryTree\ImapEngine\Idle\Events;

use DirectoryTree\ImapEngine\Vanished;

class MessagesVanished extends ResponseEvent
{
    /**
     * Get the vanished message UIDs.
     */
    public function uids(): array
    {
        return Vanished::fromResponse($this->response)->uids();
    }

    /**
     * Determine if the messages vanished before the requested checkpoint.
     */
    public function earlier(): bool
    {
        return Vanished::fromResponse($this->response)->earlier();
    }
}
