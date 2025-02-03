<?php

namespace DirectoryTree\ImapEngine;

class Address
{
    /**
     * Constructor.
     */
    public function __construct(
        public string $email,
        public string $name,
    ) {}
}
