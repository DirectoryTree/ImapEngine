<?php

namespace DirectoryTree\ImapEngine\MessageData;

enum Attribute: string implements FetchItem
{
    case Flags = 'FLAGS';
    case Size = 'RFC822.SIZE';
    case BodyStructure = 'BODYSTRUCTURE';
    case ModSequence = 'MODSEQ';

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
