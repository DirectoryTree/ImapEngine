<?php

namespace DirectoryTree\ImapEngine;

class Address
{
    /**
     * Constructor.
     */
    public function __construct(
        protected string $email,
        protected string $name,
    ) {}

    /**
     * Get the address's email.
     */
    public function email(): string
    {
        return $this->email;
    }

    /**
     * Get the address's name.
     */
    public function name(): string
    {
        return $this->name;
    }
}
