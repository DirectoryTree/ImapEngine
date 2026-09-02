<?php

namespace DirectoryTree\ImapEngine;

interface Authenticator
{
    /**
     * Get the SASL mechanism name.
     */
    public function mechanism(): string;

    /**
     * Get the unencoded initial response, or null to await a challenge.
     */
    public function initialResponse(): ?string;

    /**
     * Respond to a decoded challenge. Return null to cancel authentication.
     */
    public function respond(string $challenge): ?string;
}
