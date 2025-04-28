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
     * Prefix a string with the given prefix if it does not already start with it.
     */
    public static function prefix(string $value, string $prefix): string
    {
        return str_starts_with($value, $prefix) ? $value : $prefix.$value;
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
     * Decode a modified UTF-7 string (IMAP specific) to UTF-8.
     */
    public static function decodeUtf7Imap(string $string): string
    {
        // If the string doesn't contain any '&' character, it's not UTF-7 encoded.
        if (! str_contains($string, '&')) {
            return $string;
        }

        // Handle the special case of '&-' which represents '&' in UTF-7.
        if ($string === '&-') {
            return '&';
        }

        // Direct implementation of IMAP's modified UTF-7 decoding.
        return preg_replace_callback('/&([^-]*)-?/', function ($matches) {
            // If it's just an ampersand.
            if ($matches[1] === '') {
                return '&';
            }

            // If it's the special case for ampersand.
            if ($matches[1] === '-') {
                return '&';
            }

            // Convert modified base64 to standard base64.
            $base64 = strtr($matches[1], ',', '/');

            // Add padding if necessary.
            switch (strlen($base64) % 4) {
                case 1: $base64 .= '===';
                    break;
                case 2: $base64 .= '==';
                    break;
                case 3: $base64 .= '=';
                    break;
            }

            // Decode base64 to binary.
            $binary = base64_decode($base64, true);

            if ($binary === false) {
                // If decoding fails, return the original string.
                return '&'.$matches[1].($matches[2] ?? '');
            }

            $result = '';

            // Convert binary UTF-16BE to UTF-8.
            for ($i = 0; $i < strlen($binary); $i += 2) {
                if (isset($binary[$i + 1])) {
                    $char = (ord($binary[$i]) << 8) | ord($binary[$i + 1]);

                    if ($char < 0x80) {
                        $result .= chr($char);
                    } elseif ($char < 0x800) {
                        $result .= chr(0xC0 | ($char >> 6)).chr(0x80 | ($char & 0x3F));
                    } else {
                        $result .= chr(0xE0 | ($char >> 12)).chr(0x80 | (($char >> 6) & 0x3F)).chr(0x80 | ($char & 0x3F));
                    }
                }
            }

            return $result;
        }, $string);
    }

    public static function is($pattern, $value, $ignoreCase = false)
    {
        $value = (string) $value;

        if (! is_iterable($pattern)) {
            $pattern = [$pattern];
        }

        foreach ($pattern as $pattern) {
            $pattern = (string) $pattern;

            // If the given value is an exact match we can of course return true right
            // from the beginning. Otherwise, we will translate asterisks and do an
            // actual pattern match against the two strings to see if they match.
            if ($pattern === '*' || $pattern === $value) {
                return true;
            }

            if ($ignoreCase && mb_strtolower($pattern) === mb_strtolower($value)) {
                return true;
            }

            $pattern = preg_quote($pattern, '#');

            // Asterisks are translated into zero-or-more regular expression wildcards
            // to make it convenient to check if the strings starts with the given
            // pattern such as "library/*", making any string check convenient.
            $pattern = str_replace('\*', '.*', $pattern);

            if (preg_match('#^'.$pattern.'\z#'.($ignoreCase ? 'isu' : 'su'), $value) === 1) {
                return true;
            }
        }

        return false;
    }
}
