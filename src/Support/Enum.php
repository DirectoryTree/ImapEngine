<?php

namespace DirectoryTree\ImapEngine\Support;

use BackedEnum;

class Enum
{
    /**
     * Resolve the value of the given enums.
     */
    public static function values(BackedEnum|array|string|null $enums = null): array|string|null
    {
        if (is_null($enums)) {
            return null;
        }

        if (is_array($enums)) {
            return array_map([static::class, 'values'], $enums);
        }

        return static::value($enums);
    }

    /**
     * Resolve the value of the given enum.
     */
    public static function value(BackedEnum|string $enum): string
    {
        if ($enum instanceof BackedEnum) {
            return $enum->value;
        }

        return (string) $enum;
    }
}
