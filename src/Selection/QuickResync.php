<?php

namespace DirectoryTree\ImapEngine\Selection;

use DirectoryTree\ImapEngine\Connection\CommandArgument;
use DirectoryTree\ImapEngine\Support\SequenceSet;
use InvalidArgumentException;

class QuickResync implements OptionInterface, RequiresEnablementInterface
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
    ) {
        if ($uidValidity < 1 || $uidValidity > 4294967295) {
            throw new InvalidArgumentException('Invalid IMAP UID validity value.');
        }

        if ($highestModSequence < 1) {
            throw new InvalidArgumentException('Invalid IMAP modification sequence.');
        }
    }

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

        if ($this->knownUids !== []) {
            $parameters[] = $this->sequenceSet($this->knownUids);
        }

        if (! is_null($this->sequenceMatch)) {
            $parameters[] = CommandArgument::list($this->sequenceMatch());
        }

        return 'QRESYNC '.CommandArgument::list($parameters);
    }

    /**
     * Make a UID set that can be used in a QRESYNC parameter.
     */
    protected function sequenceSet(array|int|string $set): string
    {
        $set = SequenceSet::format($set);

        if ($set === '$' || str_contains($set, '*')) {
            throw new InvalidArgumentException('Invalid QRESYNC UID set.');
        }

        return $set;
    }

    /**
     * Make and validate the message sequence match data.
     */
    protected function sequenceMatch(): array
    {
        if (! array_is_list($this->sequenceMatch) || count($this->sequenceMatch) !== 2) {
            throw new InvalidArgumentException('Invalid QRESYNC sequence match data.');
        }

        $sets = array_map($this->sequenceSet(...), $this->sequenceMatch);

        if ($this->ascendingSetLength($sets[0]) !== $this->ascendingSetLength($sets[1])) {
            throw new InvalidArgumentException('Invalid QRESYNC sequence match data.');
        }

        return $sets;
    }

    /**
     * Get the number of values in an ascending sequence set.
     */
    protected function ascendingSetLength(string $set): int
    {
        $length = 0;
        $previous = 0;

        foreach (explode(',', $set) as $sequence) {
            [$start, $end] = array_pad(array_map('intval', explode(':', $sequence, 2)), 2, null);
            $end ??= $start;

            if ($start <= $previous || $end < $start) {
                throw new InvalidArgumentException('Invalid QRESYNC sequence match data.');
            }

            $length += $end - $start + 1;
            $previous = $end;
        }

        return $length;
    }
}
