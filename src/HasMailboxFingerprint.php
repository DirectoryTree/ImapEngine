<?php

namespace DirectoryTree\ImapEngine;

trait HasMailboxFingerprint
{
    /**
     * Get a stable identifier for this mailbox account.
     */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            $this->config('host'),
            (int) $this->config('port', 993),
            $this->config('username'),
        ], JSON_THROW_ON_ERROR));
    }
}
