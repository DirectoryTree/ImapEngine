<?php

namespace DirectoryTree\ImapEngine\Connection;

use DirectoryTree\ImapEngine\Support\Str;
use InvalidArgumentException;

class CommandArgument
{
    /**
     * Make a list with literals or nested lists.
     */
    public static function list(array $list): string
    {
        $values = [];

        foreach ($list as $value) {
            if (is_array($value)) {
                $values[] = static::list($value);
            } else {
                $values[] = $value;
            }
        }

        return sprintf('(%s)', implode(' ', $values));
    }

    /**
     * Make one or more literals.
     */
    public static function literal(array|string $string): array|string
    {
        if (is_array($string)) {
            $result = [];

            foreach ($string as $value) {
                $result[] = static::literal($value);
            }

            return $result;
        }

        if (str_contains($string, "\r") || str_contains($string, "\n")) {
            return ['{'.strlen($string).'}', $string];
        }

        return '"'.Str::escape($string).'"';
    }

    /**
     * Make an IMAP charset name.
     */
    public static function charset(string $charset): string
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $charset)) {
            throw new InvalidArgumentException('Invalid IMAP charset.');
        }

        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/', $charset)) {
            return $charset;
        }

        return '"'.Str::escape($charset).'"';
    }

    /**
     * Make an IMAP atom.
     */
    public static function atom(string $atom): string
    {
        if ($atom === '' || preg_match('/[^\x21-\x7E]/', $atom) || strpbrk($atom, '(){}%*"\\]') !== false) {
            throw new InvalidArgumentException('Invalid IMAP atom.');
        }

        return $atom;
    }

    /**
     * Make a nested list of IMAP atoms.
     */
    public static function atoms(array $atoms): array
    {
        return array_map(
            fn (array|string $atom) => is_array($atom)
                ? static::atoms($atom)
                : static::atom($atom),
            $atoms,
        );
    }

    /**
     * Make an IMAP flag.
     */
    public static function flag(string $flag): string
    {
        return str_starts_with($flag, '\\')
            ? '\\'.static::atom(substr($flag, 1))
            : static::atom($flag);
    }

    /**
     * Make a SASL mechanism name.
     */
    public static function mechanism(string $mechanism): string
    {
        if (! preg_match('/\A[A-Z0-9_-]{1,20}\z/', $mechanism)) {
            throw new InvalidArgumentException('Invalid SASL mechanism.');
        }

        return $mechanism;
    }

    /**
     * Make a parenthesized list of strings or NIL values, preserving literal boundaries.
     *
     * @param  array<string|null>  $values
     */
    public static function literalList(array $values): array
    {
        if (! $values) {
            return ['()'];
        }

        $tokens = array_map(
            fn (?string $value) => is_null($value) ? 'NIL' : static::literal($value),
            array_values($values)
        );

        $last = count($tokens) - 1;

        if (is_array($tokens[0])) {
            $tokens[0][0] = '('.$tokens[0][0];
        } else {
            $tokens[0] = '('.$tokens[0];
        }

        if (is_array($tokens[$last])) {
            $tokens[$last][1] .= ')';
        } else {
            $tokens[$last] .= ')';
        }

        return $tokens;
    }
}
