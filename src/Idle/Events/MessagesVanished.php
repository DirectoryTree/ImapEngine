<?php

namespace DirectoryTree\ImapEngine\Idle\Events;

use DirectoryTree\ImapEngine\Vanished;
use Illuminate\Support\LazyCollection;

class MessagesVanished extends ResponseEvent
{
    /**
     * Get the vanished message UIDs.
     *
     * @return LazyCollection<int, int>
     */
    public function uids(): LazyCollection
    {
        return Vanished::fromResponse($this->response)->uids();
    }

    /**
     * Determine whether the server marked the VANISHED response as EARLIER.
     */
    public function earlier(): bool
    {
        return Vanished::fromResponse($this->response)->earlier();
    }
}
