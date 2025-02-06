<?php

namespace DirectoryTree\ImapEngine\Connection\Tokens;

abstract class Token
{
    /**
     * Constructor.
     */
    public function __construct(
        public string $value,
    ) {}
}
