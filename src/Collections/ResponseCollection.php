<?php

namespace DirectoryTree\ImapEngine\Collections;

use DirectoryTree\ImapEngine\Connection\Responses\ContinuationResponse;
use DirectoryTree\ImapEngine\Connection\Responses\TaggedResponse;
use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
use Illuminate\Support\Collection;

class ResponseCollection extends Collection
{
    /**
     * Filter the collection to only tagged responses.
     */
    public function tagged(): self
    {
        return $this->whereInstanceOf(TaggedResponse::class);
    }

    /**
     * Filter the collection to only untagged responses.
     */
    public function untagged(): self
    {
        return $this->whereInstanceOf(UntaggedResponse::class);
    }

    /**
     * Filter the collection to only continuation responses.
     */
    public function continuation(): self
    {
        return $this->whereInstanceOf(ContinuationResponse::class);
    }
}
