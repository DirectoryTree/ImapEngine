<?php

namespace DirectoryTree\ImapEngine\Connection;

use Exception;

class ImapParser
{
    /**
     * The tokenizer instance that provides tokens from the raw stream.
     */
    protected ImapTokenizer $tokenizer;

    /**
     * The current token being parsed.
     *
     * Expected to be an associative array with keys like "type" and "value".
     */
    protected ?array $currentToken = null;

    /**
     * Constructor.
     */
    public function __construct(ImapTokenizer $tokenizer)
    {
        $this->tokenizer = $tokenizer;

        $this->advance(); // Load the first token.
    }

    /**
     * Parse an IMAP response.
     *
     * This method dispatches to specialized methods based on the type
     * and value of the current token. It expects that the tokenizer
     * will eventually emit an end-of-response marker (CRLF).
     *
     * @throws Exception if the response is empty.
     */
    public function parse(): mixed
    {
        if ($this->currentToken === null) {
            throw new Exception('Empty response');
        }

        // If the current token is a quoted string or a literal,
        // return it directly as a simple element.
        if (in_array($this->currentToken['type'], ['QUOTED_STRING', 'LITERAL'])) {
            return $this->parseElement();
        }

        // If the token indicates the beginning of a list, delegate to parseList().
        if ($this->currentToken['type'] === 'LIST_OPEN') {
            return $this->parseList();
        }

        // If the token is an ATOM, check its value for special markers.
        if ($this->currentToken['type'] === 'ATOM') {
            $value = $this->currentToken['value'];

            // '*' marks an untagged response.
            if ($value === '*') {
                return $this->parseUntaggedResponse();
            }

            // '+' marks a continuation response.
            if ($value === '+') {
                return $this->parseContinuationResponse();
            }
        }

        // If none of the above, assume it's a tagged response.
        return $this->parseTaggedResponse();
    }

    /**
     * Parse an untagged response.
     *
     * An untagged response begins with the '*' token.
     *
     * This method collects tokens until the end-of-response marker (CRLF) is encountered.
     */
    protected function parseUntaggedResponse(): array
    {
        $result = [];

        // Capture the initial '*' token.
        $result[] = $this->currentToken['value'];

        $this->advance();

        // Loop to collect the rest of the tokens for this response until we reach CRLF.
        while ($this->currentToken !== null && ! $this->isEndOfResponseToken($this->currentToken)) {
            $result[] = $this->parseElement();
        }

        // If the end-of-response marker (CRLF) is present, consume it.
        if ($this->currentToken !== null && $this->isEndOfResponseToken($this->currentToken)) {
            $this->advance();
        }

        return $result;
    }

    /**
     * Parse a continuation response.
     *
     * A continuation response starts with a '+' token, indicating that the
     * server expects additional data from the client. This method collects
     * tokens until the CRLF end-of-response marker is reached.
     */
    protected function parseContinuationResponse(): mixed
    {
        // Consume the '+' token.
        $this->advance();

        $elements = [];

        // Collect all tokens until the CRLF marker.
        while ($this->currentToken !== null && ! $this->isEndOfResponseToken($this->currentToken)) {
            $elements[] = $this->parseElement();
        }

        // Consume the CRLF marker if present.
        if ($this->currentToken !== null && $this->isEndOfResponseToken($this->currentToken)) {
            $this->advance();
        }

        return $elements;
    }

    /**
     * Parse a tagged response.
     *
     * A tagged response begins with a tag (which is not '*' or '+') and is followed
     * by a status and optional data. This method collects all tokens until CRLF.
     *
     * @return array An associative array with the tag and an array of status tokens.
     */
    protected function parseTaggedResponse(): array
    {
        // Capture the tag from the current token.
        $tag = $this->currentToken['value'];

        $this->advance();

        $statusTokens = [];

        // Collect tokens until the end-of-response marker is reached.
        while ($this->currentToken !== null && ! $this->isEndOfResponseToken($this->currentToken)) {
            $statusTokens[] = $this->parseElement();
        }

        // Consume the CRLF marker if present.
        if ($this->currentToken !== null && $this->isEndOfResponseToken($this->currentToken)) {
            $this->advance();
        }

        return ['tag' => $tag, 'status' => $statusTokens];
    }

    /**
     * Parses a single element, which might be a list or a simple token.
     *
     * If the current token starts a list, this delegates to parseList().
     * Otherwise, it returns the token's value.
     *
     * @return array|string|null The parsed element.
     */
    protected function parseElement(): array|string|null
    {
        // If there is no current token, return null.
        if ($this->currentToken === null) {
            return null;
        }

        // If the token indicates the start of a list, parse it as a list.
        if ($this->currentToken['type'] === 'LIST_OPEN') {
            return $this->parseList();
        }

        // Otherwise, capture the value of the token.
        $value = $this->currentToken['value'];

        $this->advance();

        return $value;
    }

    /**
     * Parses a list of elements delimited by '(' and ')'.
     *
     * Lists are handled recursively: a list may contain nested lists.
     *
     * @return array The parsed list of elements.
     *
     * @throws Exception if the list is unterminated.
     */
    protected function parseList(): array
    {
        // Consume the opening '(' token.
        $this->advance();

        $elements = [];

        // Continue to parse elements until we find the corresponding ')'.
        while ($this->currentToken !== null && $this->currentToken['type'] !== 'LIST_CLOSE') {
            $elements[] = $this->parseElement();
        }

        // If we reached the end without finding a closing ')', throw an exception.
        if ($this->currentToken === null) {
            throw new Exception('Unterminated list in response');
        }

        // Consume the closing ')' token.
        $this->advance();

        return $elements;
    }

    /**
     * Advance to the next token from the tokenizer.
     */
    protected function advance(): void
    {
        $this->currentToken = $this->tokenizer->nextToken();
    }

    /**
     * Determine if the given token marks the end of a response.
     *
     * In this implementation, a token with the value "\r\n" is
     * considered to be the end-of-response marker.
     */
    protected function isEndOfResponseToken(array $token): bool
    {
        return $token['value'] === "\r\n";
    }
}
