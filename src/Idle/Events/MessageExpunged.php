<?php

namespace DirectoryTree\ImapEngine\Idle\Events;

class MessageExpunged extends ResponseEvent
{
    /**
     * Get the expunged message's sequence number. This is not a UID.
     */
    public function sequenceNumber(): int
    {
        return (int) $this->response->type()->value;
    }
}
