<?php

namespace DirectoryTree\ImapEngine;

trait HasMailboxFingerprint
{
    /**
     * Get a stable identifier for this mailbox account.
     */
    public function fingerprint(): string
    {
        $username = (string) $this->config('username');

        $account = [
            (string) $this->config('host'),
            (int) $this->config('port', 993),
            $username,
        ];

        if ($this->config('authentication') === 'external' && $username === '') {
            $account[] = $this->config('tls.local_cert');
        }

        return hash('sha256', json_encode($account, JSON_THROW_ON_ERROR));
    }
}
