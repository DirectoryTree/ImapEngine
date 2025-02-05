<?php

namespace DirectoryTree\ImapEngine\Connection;

use Exception;

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
     * Skips whitespace characters.
     */
    protected function skipWhitespace(): void
    {
        while (true) {
            $this->ensureBuffer(1);
            $char = $this->currentChar();

            if ($char === null || ! ctype_space($char)) {
                break;
            }

            $this->advance();
        }
    }

    /**
     * Returns the next token from the stream.
     *
     * The token is an array with 'type' and 'value' keys.
     *
     * @throws Exception
     */
    public function nextToken(): ?array
    {
        $this->skipWhitespace();

        $this->ensureBuffer(1);

        $char = $this->currentChar();

        if ($char === null) {
            return null;
        }

        // Check for list delimiters.
        if ($char === '(') {
            $this->advance();

            return ['type' => 'LIST_OPEN', 'value' => '('];
        }

        if ($char === ')') {
            $this->advance();

            return ['type' => 'LIST_CLOSE', 'value' => ')'];
        }

        // Check for quoted string.
        if ($char === '"') {
            return $this->readQuotedString();
        }

        // Check for literal block.
        if ($char === '{') {
            return $this->readLiteral();
        }

        // Otherwise, parse an atom.
        return $this->readAtom();
    }

    /**
     * Reads a quoted string token.
     *
     * Quoted strings are enclosed in double quotes and may contain escaped characters.
     *
     * @throws Exception
     */
    protected function readQuotedString(): array
    {
        // Skip the opening quote.
        $this->advance();

        $value = '';

        while (true) {
            $this->ensureBuffer(1);
            $char = $this->currentChar();

            if ($char === null) {
                throw new Exception('Unterminated quoted string');
            }

            if ($char === '\\') {
                $this->advance(); // Skip the backslash.
                $this->ensureBuffer(1);
                $escapedChar = $this->currentChar();

                if ($escapedChar === null) {
                    throw new Exception('Unterminated escape sequence in quoted string');
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

        return ['type' => 'QUOTED_STRING', 'value' => $value];
    }

    /**
     * Reads a literal token.
     *
     * Literal blocks in IMAP have the form {<size>}\r\n<data>.
     *
     * @throws Exception
     */
    protected function readLiteral(): array
    {
        // Skip the opening '{'.
        $this->advance();

        $numStr = '';

        while (true) {
            $this->ensureBuffer(1);
            $char = $this->currentChar();

            if ($char === null) {
                throw new Exception('Unterminated literal specifier');
            }

            if ($char === '}') {
                $this->advance(); // Skip the '}'.
                break;
            }

            $numStr .= $char;

            $this->advance();
        }

        // Expect CRLF after the literal specifier.
        $this->ensureBuffer(2);
        $crlf = substr($this->buffer, $this->pos, 2);

        if ($crlf !== "\r\n") {
            throw new Exception('Expected CRLF after literal specifier');
        }

        $this->advance(2);
        $length = (int) $numStr;

        $literal = '';

        // First, use any data that is already in our buffer.
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
                throw new Exception('Unable to read complete literal block from stream');
            }

            $literal .= $data;
        }

        return ['type' => 'LITERAL', 'value' => $literal];
    }

    /**
     * Reads an atom token.
     *
     * Atoms are unquoted strings ending at whitespace or a delimiter.
     */
    protected function readAtom(): array
    {
        $value = '';

        while (true) {
            $this->ensureBuffer(1);
            $char = $this->currentChar();

            if ($char === null || ctype_space($char) || in_array($char, ['(', ')'])) {
                break;
            }

            // Do not cross into the start of a literal or quoted string.
            if ($char === '{' || $char === '"') {
                break;
            }

            $value .= $char;

            $this->advance();
        }

        return ['type' => 'ATOM', 'value' => $value];
    }
}
