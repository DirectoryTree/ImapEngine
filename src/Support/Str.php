<?php

namespace DirectoryTree\ImapEngine\Support;

use BackedEnum;

class Str
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

        if (str_contains($string, "\n")) {
            return ['{'.strlen($string).'}', $string];
        }

        return '"'.static::escape($string).'"';
    }

    /**
     * Resolve the value of the given enums.
     */
    public static function enums(BackedEnum|array|string|null $enums = null): array|string|null
    {
        if (is_null($enums)) {
            return null;
        }

        if (is_array($enums)) {
            return array_map([static::class, 'enums'], $enums);
        }

        return Str::enum($enums);
    }

    /**
     * Resolve the value of the given enum.
     */
    public static function enum(BackedEnum|string $enum): string
    {
        if ($enum instanceof BackedEnum) {
            return $enum->value;
        }

        return (string) $enum;
    }

    /**
     * Make a range set for use in a search command.
     */
    public static function set(array|int $from, int|float|null $to = null): string
    {
        // If $from is an array with multiple elements, return them as a comma-separated list.
        if (is_array($from) && count($from) > 1) {
            return implode(',', $from);
        }

        // If $from is an array with a single element, return that element.
        if (is_array($from) && count($from) === 1) {
            return (string) reset($from);
        }

        // At this point, $from is an integer. No upper bound provided, return $from as a string.
        if (is_null($to)) {
            return (string) $from;
        }

        // If the upper bound is infinite, use the '*' notation.
        if ($to == INF) {
            return $from.':*';
        }

        // Otherwise, return a typical range string.
        return $from.':'.$to;
    }

    /**
     * Make a credentials string for use in the AUTHENTICATE command.
     */
    public static function credentials(string $user, string $token): string
    {
        return base64_encode("user=$user\1auth=Bearer $token\1\1");
    }

    /**
     * Escape a string for use in a list.
     */
    public static function escape(string $string): string
    {
        // Remove newlines and control characters (ASCII 0-31 and 127).
        $string = preg_replace('/[\r\n\x00-\x1F\x7F]/', '', $string);

        // Escape backslashes first to avoid double-escaping and then escape double quotes.
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $string);
    }
}
