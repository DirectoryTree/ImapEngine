<?php

namespace DirectoryTree\ImapEngine\Connection;

class RawQueryValue
{
    /**
     * Constructor.
     */
    public function __construct(
        public readonly mixed $value
    ) {}
}
