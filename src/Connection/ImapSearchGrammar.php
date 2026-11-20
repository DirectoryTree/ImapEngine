<?php

namespace DirectoryTree\ImapEngine\Connection;

use DirectoryTree\ImapEngine\Support\Assert;
use DirectoryTree\ImapEngine\Support\Str;

/**
 * Compile IMAP search conditions into a query string or command tokens.
 */
class ImapSearchGrammar
{
    /**
     * Render the criteria using the public single-line query format.
     */
    public function toImap(array $wheres): string
    {
        return trim(implode('', $this->compileWhereParts($wheres, false)));
    }

    /**
     * Compile criteria for transmission to the server.
     *
     * @return list<string|ImapCommandArgument>
     */
    public function compile(array $wheres): array
    {
        $parts = $this->compileWhereParts($wheres, true);

        foreach ($parts as $part) {
            if (is_array($part)) {
                return [new ImapCommandArgument($parts)];
            }
        }

        return [trim(implode('', $parts), ' ')];
    }

    /**
     * Compile the search expression into syntax and literal arguments.
     *
     * @return list<string|array{string, string}>
     */
    protected function compileWhereParts(array $wheres, bool $forTransmission): array
    {
        $parts = [];

        foreach ($wheres as $where) {
            $expression = $where['type'] === 'nested'
                ? $this->compileWhereParts($where['query']->getWheres(), $forTransmission)
                : $this->compileBasicParts($where, $forTransmission);

            if ($parts === []) {
                $parts = $expression;
            } elseif ($where['boolean'] === 'OR') {
                $parts = ['OR (', ...$parts, ') (', ...$expression, ')'];
            } elseif ($expression !== []) {
                array_push($parts, ' ', ...$expression);
            }
        }

        return $parts;
    }

    /**
     * Compile a basic where condition into syntax and literal arguments.
     *
     * @param  array{key: string, not: bool, value: RawQueryValue|string|null}  $where
     * @return list<string|array{string, string}>
     */
    protected function compileBasicParts(array $where, bool $forTransmission): array
    {
        $key = strtoupper($where['key']);
        $part = ($where['not'] ? 'NOT ' : '').$key;
        $value = $where['value'];

        if ($value instanceof RawQueryValue) {
            return [$part.' '.$value->value];
        }

        if ($value === null) {
            return [$part];
        }

        if (! $forTransmission) {
            $value = Str::escape($value);

            return [$value ? $part.' "'.Str::toImapUtf7($value).'"' : $part];
        }

        if ($key === 'KEYWORD' || $key === 'UNKEYWORD') {
            Assert::isValidKeyword($value);

            return [$part.' '.$value];
        }

        if (in_array(explode(' ', $key, 2)[0], ['BCC', 'BODY', 'CC', 'FROM', 'HEADER', 'SUBJECT', 'TEXT', 'TO'], true)) {
            return [$part.' ', Str::literal($value)];
        }

        Assert::hasNoControlCharacters($value);

        return [$part.' "'.Str::toImapUtf7(Str::escape($value)).'"'];
    }
}
