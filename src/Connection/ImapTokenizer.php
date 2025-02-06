<?php

namespace DirectoryTree\ImapEngine\Connection;

use DirectoryTree\ImapEngine\Connection\Tokens\Atom;
use DirectoryTree\ImapEngine\Connection\Tokens\Crlf;
use DirectoryTree\ImapEngine\Connection\Tokens\GroupClose;
use DirectoryTree\ImapEngine\Connection\Tokens\GroupOpen;
use DirectoryTree\ImapEngine\Connection\Tokens\ListClose;
use DirectoryTree\ImapEngine\Connection\Tokens\ListOpen;
use DirectoryTree\ImapEngine\Connection\Tokens\Literal;
use DirectoryTree\ImapEngine\Connection\Tokens\QuotedString;
use DirectoryTree\ImapEngine\Connection\Tokens\Token;

class ImapTokenizer
{
    /**
     * The stream instance.
     */
    protected StreamInterface $stream;

    /**
     * The buffer of characters read from the stream.
     */
    protected string $buffer = '';

    /**
     * The current position in the buffer.
     */
    protected int $pos = 0;

    /**
     * Constructor.
     */
    public function __construct(StreamInterface $stream)
    {
        $this->stream = $stream;
    }

    /**
     * Ensures that at least the given length in characters are available in the buffer.
     */
    protected function ensureBuffer(int $length): void
    {
        while ((strlen($this->buffer) - $this->pos) < $length) {
            $data = $this->stream->fgets();

            if ($data === false) {
                break;
            }

            $this->buffer .= $data;
        }
    }

    /**
     * Returns the current character in the buffer.
     */
    protected function currentChar(): ?string
    {
        if ($this->pos < strlen($this->buffer)) {
            return $this->buffer[$this->pos];
        }

        return null;
    }

    /**
     * Advances the internal pointer by $n characters.
     */
    protected function advance(int $n = 1): void
    {
        $this->pos += $n;

        // If we have consumed the entire buffer, reset it.
        if ($this->pos >= strlen($this->buffer)) {
            $this->buffer = '';
            $this->pos = 0;
        }
    }

    /**
     * Returns the next token from the stream.
     *
     * The token is an array with 'type' and 'value' keys.
     *
     * @throws ImapParseException
     */
    public function nextToken(): ?Token
    {
        $this->skipWhitespace();

        $this->ensureBuffer(1);

        $char = $this->currentChar();

        if ($char === null) {
            return null;
        }

        // Check for carriage return.
        if ($char === "\r") {
            // Skip the carriage return.
            $this->advance(2);
            $this->ensureBuffer(1);

            return new Crlf("\r\n");
        }

        // Check for list open.
        if ($char === '(') {
            $this->advance();

            return new ListOpen('(');
        }

        // Check for list close.
        if ($char === ')') {
            $this->advance();

            return new ListClose(')');
        }

        // Check for bracket open.
        if ($char === '[') {
            $this->advance();

            return new GroupOpen('[');
        }

        // Check for bracket close.
        if ($char === ']') {
            $this->advance();

            return new GroupClose(']');
        }

        // Check for quoted string.
        if ($char === '"') {
            return $this->readQuotedString();
        }

        // Check for literal block open.
        if ($char === '{') {
            return $this->readLiteral();
        }

        // Otherwise, parse an atom.
        return $this->readAtom();
    }

    /**
     * Skips whitespace characters (spaces and tabs only, preserving CRLF).
     */
    protected function skipWhitespace(): void
    {
        while (true) {
            $this->ensureBuffer(1);
            $char = $this->currentChar();

            // Break on EOF.
            if ($char === null) {
                break;
            }

            // Break on CRLF.
            if ($char === "\r" || $char === "\n") {
                break;
            }

            // Break on non-whitespace.
            if ($char !== ' ' && $char !== "\t") {
                break;
            }

            $this->advance();
        }
    }

    /**
     * Reads a quoted string token.
     *
     * Quoted strings are enclosed in double quotes and may contain escaped characters.
     *
     * @throws ImapParseException
     */
    protected function readQuotedString(): QuotedString
    {
        // Skip the opening quote.
        $this->advance();

        $value = '';

        while (true) {
            $this->ensureBuffer(1);

            $char = $this->currentChar();

            if ($char === null) {
                throw new ImapParseException('Unterminated quoted string');
            }

            if ($char === '\\') {
                $this->advance(); // Skip the backslash.

                $this->ensureBuffer(1);

                $escapedChar = $this->currentChar();

                if ($escapedChar === null) {
                    throw new ImapParseException('Unterminated escape sequence in quoted string');
                }

                $value .= $escapedChar;

                $this->advance();

                continue;
            }

            if ($char === '"') {
                $this->advance(); // Skip the closing quote.

                break;
            }

            $value .= $char;

            $this->advance();
        }

        return new QuotedString($value);
    }

    /**
     * Reads a literal token.
     *
     * Literal blocks in IMAP have the form {<size>}\r\n<data>.
     *
     * @throws ImapParseException
     */
    protected function readLiteral(): Literal
    {
        // Skip the opening '{'.
        $this->advance();

        // This will contain the size of the literal block in a sequence of digits.
        // {<size>}\r\n<data>
        $numStr = '';

        while (true) {
            $this->ensureBuffer(1);
            $char = $this->currentChar();

            if ($char === null) {
                throw new ImapParseException('Unterminated literal specifier');
            }

            if ($char === '}') {
                $this->advance(); // Skip the '}'.

                break;
            }

            $numStr .= $char;

            $this->advance();
        }

        // Expect carriage return after the literal specifier.
        $this->ensureBuffer(2);

        // Get the carriage return.
        $crlf = substr($this->buffer, $this->pos, 2);

        if ($crlf !== "\r\n") {
            throw new ImapParseException('Expected CRLF after literal specifier');
        }

        $this->advance(2);

        $length = (int) $numStr;

        // Use any data that is already in our buffer.
        $available = strlen($this->buffer) - $this->pos;

        if ($available >= $length) {
            $literal = substr($this->buffer, $this->pos, $length);

            $this->advance($length);
        } else {
            $literal = substr($this->buffer, $this->pos);

            // Flush the current buffer.
            $this->buffer = '';
            $this->pos = 0;

            $remaining = $length - strlen($literal);

            $data = $this->stream->read($remaining);

            if ($data === false || strlen($data) !== $remaining) {
                throw new ImapParseException('Unable to read complete literal block from stream');
            }

            $literal .= $data;
        }

        return new Literal($literal);
    }

    /**
     * Reads an atom token.
     *
     * Atoms are unquoted strings ending at whitespace or a delimiter.
     */
    protected function readAtom(): ?Atom
    {
        $value = '';

        while (true) {
            $this->ensureBuffer(1);
            $char = $this->currentChar();

            if (
                // White space.
                $char === null ||
                $char === ' ' ||
                $char === "\t" ||

                // Delimiters.
                $char === "\r" ||
                $char === "\n" ||
                $char === '(' ||
                $char === ')' ||
                $char === '[' ||
                $char === ']' ||
                $char === '{' ||
                $char === '}'
            ) {
                break;
            }

            // Append the character to the value.
            $value .= $char;

            $this->advance();
        }

        return new Atom($value);
    }
}
