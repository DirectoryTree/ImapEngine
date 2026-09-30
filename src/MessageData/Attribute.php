<?php

namespace DirectoryTree\ImapEngine\MessageData;

enum Attribute: string implements FetchItemInterface
{
    case Flags = 'FLAGS';
    case Size = 'RFC822.SIZE';
    case ModSequence = 'MODSEQ';
    case BodyStructure = 'BODYSTRUCTURE';

    /**
     * {@inheritDoc}
     */
    public function key(): string
    {
        return $this->value;
    }

    /**
     * {@inheritDoc}
     */
    public function toImap(): string
    {
        return $this->value;
    }
}
