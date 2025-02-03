<?php

namespace DirectoryTree\ImapEngine\Support;

class Escape
{
    /**
     * Escape one or more literals.
     */
    public static function string(array|string $string): array|string
    {
        if (func_num_args() < 2) {
            if (str_contains($string, "\n")) {
                return ['{'.strlen($string).'}', $string];
            } else {
                return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $string).'"';
            }
        }

        $result = [];

        foreach (func_get_args() as $string) {
            $result[] = static::string($string);
        }

        return $result;
    }

    /**
     * Escape a list with literals or lists.
     */
    public static function list(array $list): string
    {
        $result = [];

        foreach ($list as $value) {
            if (! is_array($value)) {
                $result[] = $value;

                continue;
            }

            $result[] = static::list($value);
        }

        return '('.implode(' ', $result).')';
    }
}
