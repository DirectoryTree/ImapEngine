<?php

namespace DirectoryTree\ImapEngine\Connection;

use DirectoryTree\ImapEngine\Exceptions\RuntimeException;

class ImapStreamTokenizer
{
    /**
     * Decode the given line into an array of tokens.
     */
    public function tokenize(StreamInterface $stream, string $line): array
    {
        // Replace any trailing <NL> including spaces with a single space.
        $line = rtrim($line).' ';

        $tokens = [];
        $stack = [];

        while (($pos = strpos($line, ' ')) !== false) {
            $tokenText = substr($line, 0, $pos);

            // Skip empty tokens (move the pointer forward).
            if (! strlen($tokenText)) {
                $line = substr($line, $pos + 1);

                continue;
            }

            // Handle Added bracket detection. i.e. "OK [READ-WRITE]" or "OK [PERMANENTFLAGS (\Answered ...)]".
            if (isset($tokenText[0]) && $tokenText[0] === '[') {
                $parsedBracket = $this->parseBracketedCode($line);

                $tokens[] = new ImapToken(ImapToken::TYPE_ATOM, $parsedBracket['value']);

                $line = $parsedBracket['remaining'];

                continue;
            }

            // Handle opening parentheses by pushing current tokens to stack.
            while (isset($tokenText[0]) && $tokenText[0] === '(') {
                $stack[] = $tokens;
                $tokens = [];
                $tokenText = substr($tokenText, 1);
            }

            // Parse quoted strings.
            if (isset($tokenText[0]) && $tokenText[0] === '"') {
                $parsedQuoted = $this->parseQuotedString($line);

                $tokens[] = new ImapToken(ImapToken::TYPE_QUOTED_STRING, $parsedQuoted['value']);

                $line = $parsedQuoted['remaining'];

                continue;
            }

            // Handle IMAP literal blocks: {<number>}
            if (isset($tokenText[0]) && $tokenText[0] === '{') {
                $parsedLiteral = $this->parseLiteral($stream, $line, $tokenText);

                $tokens[] = new ImapToken(ImapToken::TYPE_LITERAL, $parsedLiteral['value']);

                $line = $parsedLiteral['remaining'];

                continue;
            }

            // Handle closing parentheses and manage stack depth (if any).
            if ($stack && str_ends_with($tokenText, ')')) {
                $tokens = $this->closeParenAndMerge($stack, $tokens, $tokenText);

                $line = substr($line, $pos + 1);

                continue;
            }

            // Normal "atom" or raw text token.
            $tokens[] = new ImapToken(ImapToken::TYPE_ATOM, $tokenText);

            // Move to next segment.
            $line = substr($line, $pos + 1);
        }

        // If the server didn't send some closing parentheses, finalize them.
        while ($stack) {
            $child = $tokens;
            $tokens = array_pop($stack);
            $tokens[] = new ImapToken(ImapToken::TYPE_LIST, $child);
        }

        return $tokens;
    }

    /**
     * Parse out bracketed codes like [PERMANENTFLAGS (\Seen)].
     *
     * @return array ['value' => string, 'remaining' => string]
     */
    protected function parseBracketedCode(string $line): array
    {
        // We know the token starts with '['. Find the matching ']'.
        $endPos = strpos($line, ']');

        // If we don't find a ']', we just treat the entire token as plain.
        if ($endPos === false) {
            // Fallback: treat everything as atom
            $spacePos = strpos($line, ' ');
            $atom = ($spacePos === false) ? $line : substr($line, 0, $spacePos);

            return [
                'value' => $atom,
                'remaining' => ($spacePos === false)
                    ? ''
                    : ltrim(substr($line, $spacePos + 1)).' ',
            ];
        }

        // Extract the bracket content (without the '[' and ']'). E.g. "[PERMANENTFLAGS" => "PERMANENTFLAGS".
        $raw = substr($line, 1, $endPos - 1);

        // Move past the bracketed text, and remove leading space if any.
        $line = substr($line, $endPos + 1); // skip the ']'.
        $line = ltrim($line).' ';

        return [
            'value' => $raw,
            'remaining' => $line,
        ];
    }

    /**
     * Parse a quoted string from the given line.
     *
     * @return array ['value' => string, 'remaining' => string]
     */
    protected function parseQuotedString(string $line): array
    {
        // We want to match something like: "(... )"^"((.|\\\|\")*?)" .
        // i.e. a double-quoted string that might contain escaped characters.
        if (preg_match('%^\(*\"((.|\\\|\")*?)\"( |$)%', $line, $matches)) {
            $value = $matches[1];

            $remaining = substr($line, strlen($matches[0]));

            return [
                'value' => $value,
                'remaining' => ltrim($remaining).' ',
            ];
        }

        // Fallback if the regex didn't match.
        return [
            'value' => '',
            'remaining' => $line,
        ];
    }

    /**
     * Parse an IMAP literal block, e.g. {123}, then read 123 bytes from the stream.
     *
     * @return array ['value' => string, 'remaining' => string]
     *
     * @throws RuntimeException
     */
    protected function parseLiteral(StreamInterface $stream, string $line, string $tokenText): array
    {
        // Extract the byte count from the literal (e.g., {20}).
        $endPos = strpos($tokenText, '}');
        $chars = substr($tokenText, 1, $endPos - 1);

        if (! is_numeric($chars)) {
            // If it's not numeric, we just return it as raw text.
            return [
                'value' => $tokenText,
                'remaining' => substr($line, strlen($tokenText) + 1),
            ];
        }

        $lengthNeeded = (int) $chars;

        // Move past the literal block, and remove leading space after it.
        $line = substr($line, strpos($line, '}') + 1);
        $line = ltrim($line).' ';

        // Read exactly the number of bytes specified by the literal.
        $value = '';

        while (strlen($value) < $lengthNeeded) {
            $chunk = $stream->fgets();

            if ($chunk === false) {
                throw new RuntimeException('Failed to read literal data from stream.');
            }

            $value .= $chunk;
        }

        $excess = '';

        if (strlen($value) > $lengthNeeded) {
            // If more bytes are read than required, split the excess.
            $excess = substr($value, $lengthNeeded);
            $value = substr($value, 0, $lengthNeeded);
        }

        // Trim the leftover lines and re-append them to $line for continued parsing.
        $line = trim($excess.' '.$line).' ';

        return [
            'value' => $value,
            'remaining' => $line,
        ];
    }

    /**
     * Handle one or more closing parentheses, merging tokens with any previously stacked items.
     *
     * @param  ImapToken[]  $tokens
     * @return ImapToken[]
     */
    protected function closeParenAndMerge(array &$stack, array $tokens, string $tokenText): array
    {
        // Count how many closing parens are in a row.
        $closingCount = strlen(rtrim($tokenText, ')')) - strlen($tokenText);

        if ($closingCount < 1) {
            // If it ends with only one ')', just handle it normally.
            $closingCount = substr_count($tokenText, ')');
        }

        // Remove all trailing ')' from the token text so that we get the real token value.
        $trimmed = rtrim($tokenText, ')');

        // If there's any token text left, that is an atom to add before merging.
        if (strlen($trimmed)) {
            $tokens[] = new ImapToken(ImapToken::TYPE_ATOM, $trimmed);
        }

        $child = $tokens;

        while ($closingCount-- > 0 && $stack) {
            $tokens = array_pop($stack);
            $tokens[] = new ImapToken(ImapToken::TYPE_LIST, $child);
            $child = $tokens;
        }

        return $child;
    }
}
