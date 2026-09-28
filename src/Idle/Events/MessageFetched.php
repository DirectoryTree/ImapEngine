<?php

namespace DirectoryTree\ImapEngine\Idle\Events;

use DirectoryTree\ImapEngine\Collections\ResponseCollection;
use DirectoryTree\ImapEngine\FetchResult;

class MessageFetched extends ResponseEvent
{
    /**
     * Get the message sequence number. This is not a UID.
     */
    public function sequenceNumber(): int
    {
        return (int) $this->response->type()->value;
    }

    /**
     * Get parsed FETCH changes using the synchronization result API.
     */
    public function changes(): FetchResult
    {
        return FetchResult::fromResponses(new ResponseCollection([$this->response]));
    }
}
