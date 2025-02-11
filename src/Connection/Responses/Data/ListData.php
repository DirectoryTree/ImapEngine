<?php

namespace DirectoryTree\ImapEngine\Connection\Responses\Data;

use DirectoryTree\ImapEngine\Connection\Tokens\Token;

class ListData extends Data
{
    /**
     * Lookup the value of the given field in the list.
     */
    public function lookup(string $field): Data|Token|null
    {
        foreach ($this->tokens as $index => $token) {
            if ($token->value === $field) {
                return $this->tokenAt(++$index);
            }
        }

        return null;
    }

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
