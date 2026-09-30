<?php

namespace DirectoryTree\ImapEngine\Support;

class MessageSetMatcher
{
    /**
     * The individual message numbers in the set.
     */
    protected array $numbers = [];

    /**
     * The ranges of message numbers in the set.
     */
    protected array $ranges = [];

    /**
     * Whether the set can be filtered without server state.
     */
    protected bool $filterable = true;

    /**
     * Create a matcher from a validated IMAP message set.
     */
    public function __construct(string $set)
    {
        // Wildcards and saved searches require server state we do not have.
        // Do not discard potentially requested messages by guessing their bounds.
        if (str_contains($set, '*') || $set === '$') {
            $this->filterable = false;

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

        $this->ranges = static::mergeRanges($this->ranges);
    }

    /**
     * Determine whether the set may contain the given message number or UID.
     */
    public function contains(int $number): bool
    {
        if (! $this->filterable || isset($this->numbers[$number])) {
            return true;
        }

        $start = 0;
        $end = count($this->ranges) - 1;

        while ($start <= $end) {
            $middle = intdiv($start + $end, 2);
            [$rangeStart, $rangeEnd] = $this->ranges[$middle];

            if ($number < $rangeStart) {
                $end = $middle - 1;

                continue;
            }

            if ($number > $rangeEnd) {
                $start = $middle + 1;

                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Sort and merge overlapping or adjacent ranges.
     *
     * @param  array<int, array{0: int, 1: int}>  $ranges
     * @return array<int, array{0: int, 1: int}>
     */
    protected static function mergeRanges(array $ranges): array
    {
        usort($ranges, fn (array $left, array $right) => $left[0] <=> $right[0]);

        $merged = [];

        foreach ($ranges as [$start, $end]) {
            $last = count($merged) - 1;

            if ($last < 0 || $start > $merged[$last][1] + 1) {
                $merged[] = [$start, $end];

                continue;
            }

            $merged[$last][1] = max($merged[$last][1], $end);
        }

        return $merged;
    }
}
