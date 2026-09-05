<?php

namespace DirectoryTree\ImapEngine\Store;

/**
 * Store flags only when messages have not changed after a modification sequence.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7162#section-3.1.3
 */
class UnchangedSince implements ModifierInterface
{
    /**
     * Constructor.
     */
    public function __construct(
        protected int $modSequence
    ) {}

    /**
     * {@inheritDoc}
     */
    public function toImap(): string
    {
        return 'UNCHANGEDSINCE '.$this->modSequence;
    }
}
