<?php

namespace DirectoryTree\ImapEngine\Support;

class Str
{
    /**
     * Make a range set.
     */
    public static function set(array|int $from, int|float|null $to = null): string
    {
        if (is_array($from) && count($from) > 1) {
            return implode(',', $from);
        }

        if (is_array($from) && count($from) === 1) {
            return $from[0].':'.$from[0];
        }

        if (is_null($to)) {
            return $from;
        }

        if ($to == INF) {
            return $from.':*';
        }

        return $from.':'.$to;
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

    /**
     * Make one or more literals.
     */
    public static function literal(array|string $string): array|string
    {
        if (func_num_args() >= 2) {
            $result = [];

            foreach (func_get_args() as $string) {
                $result[] = static::literal($string);
            }

            return $result;
        }

        if (str_contains($string, "\n")) {
            return ['{'.strlen($string).'}', $string];
        }

        return '"'.static::escape($string).'"';
    }

    /**
     * Make a list with literals or lists.
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
}
