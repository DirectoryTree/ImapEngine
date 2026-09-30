<?php

namespace DirectoryTree\ImapEngine\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use InvalidArgumentException;

class SequenceSet
{
    /**
     * Make an IMAP sequence set.
     *
     * @see https://www.rfc-editor.org/rfc/rfc9051.html#section-9
     */
    public static function format(int|string|array $from, int|float|string|null $to = null): string
    {
        if (is_array($from)) {
            // Compression can omit intermediate values, so validate each one first.
            foreach ($from as $value) {
                static::assertValidSequenceSet((string) $value);
            }
        }

        $set = match (true) {
            is_array($from) => static::toSequenceSet($from),
            is_null($to) => (string) $from,
            $to == INF => $from.':*',
            default => $from.':'.$to,
        };

        static::assertValidSequenceSet($set);

        return $set;
    }

    /**
     * Assert that a sequence set can be sent as an IMAP command argument.
     */
    protected static function assertValidSequenceSet(string $set): void
    {
        if ($set === '$') {
            return;
        }

        foreach (explode(',', $set) as $sequence) {
            if (! preg_match('/\A(?:[1-9][0-9]*|\*)(?::(?:[1-9][0-9]*|\*))?\z/', $sequence)) {
                throw new InvalidArgumentException('Invalid IMAP sequence set.');
            }

            foreach (explode(':', $sequence) as $number) {
                if ($number === '*') {
                    continue;
                }

                $digits = strlen($number);

                if ($digits > 10 || ($digits === 10 && strcmp($number, '4294967295') > 0)) {
                    throw new InvalidArgumentException('Invalid IMAP sequence set.');
                }
            }
        }
    }

    /**
     * Convert the values into an IMAP sequence set.
     *
     * @param  array<int, int|string>  $values
     */
    protected static function toSequenceSet(array $values): string
    {
        return Collection::make(array_values($values))
            ->chunkWhile(function (int|string $value, int $key, Collection $range) {
                $previous = $range->last();

                if (! is_numeric($value) || ! is_numeric($previous)) {
                    return false;
                }

                $difference = (int) $value - (int) $previous;
                $direction = (int) $previous <=> (int) $range->first();

                return in_array($difference, [-1, 1], true)
                    && ($range->count() === 1 || $difference === $direction);
            })
            ->map(fn (Collection $range) => static::toSequenceRange(
                $range->first(),
                $range->last(),
            ))
            ->implode(',');
    }

    /**
     * Convert the values into an IMAP sequence range.
     */
    protected static function toSequenceRange(int|string $start, int|string $end): string
    {
        return (string) $start === (string) $end
            ? (string) $start
            : $start.':'.$end;
    }

    /**
     * Parse a numeric IMAP sequence set without expanding its ranges in memory.
     *
     * @return LazyCollection<int, int>
     */
    public static function parse(string $set): LazyCollection
    {
        return new LazyCollection(function () use ($set) {
            foreach (explode(',', $set) as $sequence) {
                if (! str_contains($sequence, ':')) {
                    yield (int) $sequence;

                    continue;
                }

                [$start, $end] = array_map('intval', explode(':', $sequence, 2));
                $step = $start <= $end ? 1 : -1;

                for ($value = $start; ; $value += $step) {
                    yield $value;

                    if ($value === $end) {
                        break;
                    }
                }
            }
        });
    }
}
