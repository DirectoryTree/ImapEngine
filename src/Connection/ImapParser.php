<?php

namespace DirectoryTree\ImapEngine\Connection;

use Exception;

class ImapParser
{
    /**
     * The tokenizer instance.
     */
    protected ImapTokenizer $tokenizer;

    /**
     * The current token being parsed.
     */
    protected ?array $currentToken = null;

    /**
     * Constructor.
     */
    public function __construct(ImapTokenizer $tokenizer)
    {
        $this->tokenizer = $tokenizer;

        $this->advance();
    }

    /**
     * Advance to the next token.
     */
    protected function advance(): void
    {
        $this->currentToken = $this->tokenizer->nextToken();
    }

    /**
     * Parse a generic IMAP response.
     */
    public function parse(): mixed
    {
        if ($this->currentToken === null) {
            throw new Exception('Empty response');
        }

        // If the token is a simple element (quoted string or literal), just return it.
        if (in_array($this->currentToken['type'], ['QUOTED_STRING', 'LITERAL'])) {
            return $this->parseElement();
        }

        // Dispatch based on token type first.
        if ($this->currentToken['type'] === 'LIST_OPEN') {
            return $this->parseList();
        }

        // Otherwise, if the token is an ATOM, check for untagged or continuation tokens.
        if ($this->currentToken['type'] === 'ATOM') {
            $value = $this->currentToken['value'];

            if ($value === '*') {
                return $this->parseUntaggedResponse();
            }

            if ($value === '+') {
                return $this->parseContinuationResponse();
            }
        }

        // Otherwise, treat it as a tagged response.
        return $this->parseTaggedResponse();
    }

    /**
     * Parse an untagged response.
     */
    protected function parseUntaggedResponse(): array
    {
        $result = [];

        // Capture the '*' token.
        $result[] = $this->currentToken['value'];

        $this->advance();

        // Then parse the rest of the response (this is a simplistic approach).
        // In a more complete implementation you might loop until end-of-line or a termination condition.
        while ($this->currentToken !== null && $this->currentToken['value'] !== "\r\n") {
            $result[] = $this->parseElement();
        }

        return $result;
    }

    /**
     * Parse a continuation response.
     */
    protected function parseContinuationResponse(): mixed
    {
        // Consume the '+' token.
        $this->advance();

        // Parse any optional prompt or literal data provided by the server.
        return $this->parseElement();
    }

    /**
     * Parse a tagged response.
     */
    protected function parseTaggedResponse(): array
    {
        // Typically, the first token will be the tag, so record it.
        $tag = $this->currentToken['value'];

        $this->advance();

        // Then parse the response status and any attached data.
        $status = $this->parseElement();

        // You might want to collect additional tokens for a full response.
        return ['tag' => $tag, 'status' => $status];
    }

    /**
     * Parses a single element which may be a list or a simple token.
     */
    protected function parseElement(): mixed
    {
        // If there is no token at all, that might be a problem only if we expected one.
        if ($this->currentToken === null) {
            // Instead of throwing here, you can choose to return null (if that’s acceptable)
            // or you can throw an exception only when you’re in the middle of a structure.
            return null;
        }

        if ($this->currentToken['type'] === 'LIST_OPEN') {
            return $this->parseList();
        }

        $value = $this->currentToken['value'];

        $this->advance();  // Advance after consuming the token.

        return $value;
    }

    /**
     * Parses a list of elements delimited by '(' and ')'.
     */
    protected function parseList(): array
    {
        // Consume the opening '('.
        $this->advance();

        $elements = [];

        while ($this->currentToken !== null && $this->currentToken['type'] !== 'LIST_CLOSE') {
            $elements[] = $this->parseElement();
        }

        if ($this->currentToken === null) {
            throw new Exception('Unterminated list in response');
        }

        // Consume the closing ')'.
        $this->advance();

        return $elements;
    }
}
