<?php

namespace DirectoryTree\ImapEngine;

trait HasCapabilities
{
    /**
     * Determine if the mailbox supports the given capability.
     */
    public function hasCapability(string $capability): bool
    {
        $capability = strtoupper($capability);

        foreach ($this->capabilities() as $supported) {
            $supported = strtoupper($supported);

            if ($supported === $capability || str_starts_with($supported, "{$capability}=")) {
                return true;
            }
        }

        return false;
    }
}
