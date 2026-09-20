<?php

namespace DirectoryTree\ImapEngine\Support;

class MessageSetMatcher
{
    protected array $numbers = [];

    protected array $ranges = [];

    protected bool $serverResolved = false;

    /**
     * Create a matcher from a validated IMAP message set.
     */
    public function __construct(string $set)
    {
        // Wildcards and saved searches require server state we do not have.
        // Do not discard potentially requested messages by guessing their bounds.
        if (str_contains($set, '*') || $set === '$') {
            $this->serverResolved = true;

            return;
        }

        foreach (explode(',', $set) as $sequence) {
            if (! str_contains($sequence, ':')) {
                $this->numbers[(int) $sequence] = true;

                continue;
            }

            [$start, $end] = array_map('intval', explode(':', $sequence, 2));

            $this->ranges[] = [min($start, $end), max($start, $end)];
        }
    }

    /**
     * Determine whether the set may contain the given message number or UID.
     */
    public function contains(int $number): bool
    {
        if ($this->serverResolved || isset($this->numbers[$number])) {
            return true;
        }

        foreach ($this->ranges as [$start, $end]) {
            if ($number >= $start && $number <= $end) {
                return true;
            }
        }

        return false;
    }
}
