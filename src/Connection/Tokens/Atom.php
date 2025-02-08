<?php

namespace DirectoryTree\ImapEngine\Connection\Tokens;

/**
 * @see https://datatracker.ietf.org/doc/html/rfc9051#name-atom
 */
class Atom extends Token
{
    /**
     * Determine if the token is the given value.
     */
    public function is(string $value): bool
    {
        return $this->value === $value;
    }

    /**
     * Determine if the token is not the given value.
     */
    public function isNot(string $value): bool
    {
        return ! $this->is($value);
    }
}
