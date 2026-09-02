<?php

namespace DirectoryTree\ImapEngine\Authentication;

use DirectoryTree\ImapEngine\Authenticator;

class XOAuth2 implements Authenticator
{
    /**
     * Constructor.
     */
    public function __construct(
        protected string $user,
        protected string $token,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function mechanism(): string
    {
        return 'XOAUTH2';
    }

    /**
     * {@inheritDoc}
     */
    public function initialResponse(): string
    {
        return "user=$this->user\1auth=Bearer $this->token\1\1";
    }

    /**
     * {@inheritDoc}
     */
    public function respond(string $challenge): string
    {
        return $challenge === '' ? $this->initialResponse() : '';
    }
}
