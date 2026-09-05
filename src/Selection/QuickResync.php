<?php

namespace DirectoryTree\ImapEngine\Selection;

use DirectoryTree\ImapEngine\Support\Str;

class QuickResync implements OptionInterface, RequiresEnableInterface
{
    /**
     * Constructor.
     *
     * Sequence matches pair ascending message numbers with their corresponding UIDs.
     *
     * @param  array{0: array|int|string, 1: array|int|string}|null  $sequenceMatch
     */
    public function __construct(
        protected int $uidValidity,
        protected int $highestModSequence,
        protected array|int|string $knownUids = [],
        protected ?array $sequenceMatch = null,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function capability(): string
    {
        return 'QRESYNC';
    }

    /**
     * {@inheritDoc}
     */
    public function toImap(): string
    {
        $parameters = [$this->uidValidity, $this->highestModSequence];

        if ($this->knownUids) {
            $parameters[] = Str::set($this->knownUids);
        }

        if (! is_null($this->sequenceMatch)) {
            $parameters[] = Str::list(array_map([Str::class, 'set'], $this->sequenceMatch));
        }

        return 'QRESYNC '.Str::list($parameters);
    }
}
