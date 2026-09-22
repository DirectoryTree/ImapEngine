<?php

namespace DirectoryTree\ImapEngine;

interface AuthenticatorInterface
{
    /**
     * Get the SASL mechanism name.
     */
    public function mechanism(): string;

    /**
     * Get the unencoded initial data, or null to await a challenge.
     */
    public function initial(): ?string;

    /**
     * Respond to a decoded challenge. Return null to cancel authentication.
     */
    public function respond(string $challenge): ?string;
}
