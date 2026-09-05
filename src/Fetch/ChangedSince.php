<?php

namespace DirectoryTree\ImapEngine\Fetch;

/**
 * Fetch messages changed after a modification sequence.
 *
 * Requesting vanished UIDs requires UID FETCH and enabled QRESYNC support.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7162#section-3.1.4
 * @see https://datatracker.ietf.org/doc/html/rfc7162#section-3.2.6
 */
class ChangedSince implements ModifierInterface
{
    /**
     * Constructor.
     */
    public function __construct(
        protected int $modSequence,
        protected bool $vanished = false,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function toImap(): string
    {
        return 'CHANGEDSINCE '.$this->modSequence.($this->vanished ? ' VANISHED' : '');
    }
}
