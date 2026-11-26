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
     * Compile criteria for transmission to the server.
     *
     * @return list<string|ImapCommandArgument>
     */
    public function compile(array $wheres): array
    {
        $parts = $this->compileWhereParts($wheres);

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
    protected function compileWhereParts(array $wheres): array
    {
        $parts = [];

        foreach ($wheres as $where) {
            $expression = $where['type'] === 'nested'
                ? $this->compileNestedParts($where['query'])
                : $this->compileBasicParts($where);

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
     * Compile nested criteria through the nested builder's public compiler.
     */
    protected function compileNestedParts(ImapQueryBuilder $query): array
    {
        $parts = [];

        foreach ($query->toImapTokens() as $token) {
            if ($token === '') {
                continue;
            }

            if ($parts !== []) {
                $parts[] = ' ';
            }

            if ($token instanceof ImapCommandArgument) {
                array_push($parts, ...$token->parts);
            } else {
                $parts[] = $token;
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
    protected function compileBasicParts(array $where): array
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
