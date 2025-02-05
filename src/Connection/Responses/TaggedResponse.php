<?php

namespace DirectoryTree\ImapEngine\Connection\Responses;

class TaggedResponse extends ImapResponse
{
    /**
     * Get the response tag.
     */
    public function tag(): ?string
    {
        return $this->tokens[0] ?? null;
    }
}
