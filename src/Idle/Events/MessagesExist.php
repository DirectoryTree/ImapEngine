<?php

namespace DirectoryTree\ImapEngine\Idle\Events;

class MessagesExist extends ResponseEvent
{
    /**
     * Get the current mailbox message count, not the number of new messages.
     */
    public function count(): int
    {
        return (int) $this->response->type()->value;
    }
}
