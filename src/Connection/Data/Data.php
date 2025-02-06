<?php

namespace DirectoryTree\ImapEngine\Connection\Data;

abstract class Data
{
    /**
     * Constructor.
     */
    public function __construct(
        protected array $tokens
    ) {}
}
