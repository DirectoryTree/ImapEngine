<?php

namespace DirectoryTree\ImapEngine\Connection\Responses\Data;

class ListData extends Data
{
    /**
     * Get the list as a string.
     */
    public function __toString(): string
    {
        return sprintf('(%s)', implode(
            ' ', array_map('strval', $this->tokens)
        ));
    }
}
