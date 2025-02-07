<?php

namespace DirectoryTree\ImapEngine\Connection;

use DirectoryTree\ImapEngine\Connection\Data\Data;
use DirectoryTree\ImapEngine\Connection\Data\ListData;
use DirectoryTree\ImapEngine\Connection\Data\ResponseCodeData;
use DirectoryTree\ImapEngine\Connection\Responses\ContinuationResponse;
use DirectoryTree\ImapEngine\Connection\Responses\Response;
use DirectoryTree\ImapEngine\Connection\Responses\TaggedResponse;
use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
use DirectoryTree\ImapEngine\Connection\Tokens\Atom;
use DirectoryTree\ImapEngine\Connection\Tokens\Crlf;
use DirectoryTree\ImapEngine\Connection\Tokens\ListClose;
use DirectoryTree\ImapEngine\Connection\Tokens\ListOpen;
use DirectoryTree\ImapEngine\Connection\Tokens\ResponseCodeClose;
use DirectoryTree\ImapEngine\Connection\Tokens\ResponseCodeOpen;
use DirectoryTree\ImapEngine\Connection\Tokens\Token;

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
    protected ?Token $currentToken = null;

    /**
     * Constructor.
     */
    public function __construct(ImapTokenizer $tokenizer)
    {
        $this->tokenizer = $tokenizer;
    }

    /**
     * Parse an IMAP response.
     *
     * This method dispatches to specialized methods based on the type
     * and value of the current token. It expects that the tokenizer
     * will eventually return an end-of-response marker (CRLF).
     *
     * @throws ImapParseException if the response is empty.
     */
    public function next(): Data|Token|Response|null
    {
        // Load the first token.
        if (! $this->currentToken) {
            $this->advance();
        }

        if (! $this->currentToken) {
            throw new ImapParseException('Empty response');
        }

        // If the token indicates the beginning of a list, parse it.
        if ($this->currentToken instanceof ListOpen) {
            return $this->parseList();
        }

        // If the token is an ATOM, check its value for special markers.
        if ($this->currentToken instanceof Atom) {
            // '*' marks an untagged response.
            if ($this->currentToken->value === '*') {
                return $this->parseUntaggedResponse();
            }

            // '+' marks a continuation response.
            if ($this->currentToken->value === '+') {
                return $this->parseContinuationResponse();
            }

            // If it's an Atom and not '*' or '+', it's likely a TAGGED response.
            return $this->parseTaggedResponse();
        }

        return $this->parseElement();
    }

    /**
     * Parse an untagged response.
     *
     * An untagged response begins with the '*' token. This
     * method collects tokens until the end-of-response
     * marker (CRLF) is encountered.
     */
    protected function parseUntaggedResponse(): UntaggedResponse
    {
        // Capture the initial '*' token.
        $elements[] = clone $this->currentToken;

        $this->advance();

        // Collect all tokens until the end-of-response marker.
        while ($this->currentToken && ! $this->isEndOfResponseToken($this->currentToken)) {
            $elements[] = $this->parseElement();
        }

        // If the end-of-response marker (CRLF) is present, consume it.
        if ($this->currentToken && $this->isEndOfResponseToken($this->currentToken)) {
            $this->advance();
        } else {
            throw new ImapParseException('Unterminated untagged response');
        }

        return new UntaggedResponse($elements);
    }

    /**
     * Parse a continuation response.
     *
     * A continuation response starts with a '+' token, indicating that the
     * server expects additional data from the client. This method collects
     * tokens until the CRLF end-of-response marker is reached.
     */
    protected function parseContinuationResponse(): ContinuationResponse
    {
        // Consume the '+' token.
        $this->advance();

        $elements = [];

        // Collect all tokens until the CRLF marker.
        while ($this->currentToken && ! $this->isEndOfResponseToken($this->currentToken)) {
            $elements[] = $this->parseElement();
        }

        // Consume the CRLF marker if present.
        if ($this->currentToken && $this->isEndOfResponseToken($this->currentToken)) {
            $this->advance();
        } else {
            throw new ImapParseException('Unterminated continuation response');
        }

        return new ContinuationResponse($elements);
    }

    /**
     * Parse a tagged response.
     *
     * A tagged response begins with a tag (which is not '*' or '+') and is followed
     * by a status and optional data. This method collects all tokens until CRLF.
     */
    protected function parseTaggedResponse(): TaggedResponse
    {
        $tokens[] = clone $this->currentToken;

        $this->advance();

        // Collect tokens until the end-of-response marker is reached.
        while ($this->currentToken && ! $this->isEndOfResponseToken($this->currentToken)) {
            $tokens[] = $this->parseElement();
        }

        // Consume the CRLF marker if present.
        if ($this->currentToken && $this->isEndOfResponseToken($this->currentToken)) {
            $this->advance();
        } else {
            throw new ImapParseException('Unterminated tagged response');
        }

        return new TaggedResponse($tokens);
    }

    /**
     * Parses a bracket group of elements delimited by '[' and ']'.
     *
     * @throws ImapParseException if the group is unterminated.
     */
    protected function parseBracketGroup(): ResponseCodeData
    {
        // Consume the opening '[' token.
        $this->advance();

        $elements = [];

        while (
            $this->currentToken
            && ! $this->currentToken instanceof ResponseCodeClose
            && ! $this->isEndOfResponseToken($this->currentToken)
        ) {
            $elements[] = $this->parseElement();
        }

        if ($this->currentToken === null) {
            throw new ImapParseException('Unterminated bracket group in response');
        }

        // Consume the closing ']' token.
        $this->advance();

        return new ResponseCodeData($elements);
    }

    /**
     * Parses a list of elements delimited by '(' and ')'.
     *
     * Lists are handled recursively: a list may contain nested lists.
     *
     * @throws ImapParseException if the list is unterminated.
     */
    protected function parseList(): ListData
    {
        // Consume the opening '(' token.
        $this->advance();

        $elements = [];

        // Continue to parse elements until we find the corresponding ')'.
        while (
            $this->currentToken
            && ! $this->currentToken instanceof ListClose
            && ! $this->isEndOfResponseToken($this->currentToken)
        ) {
            $elements[] = $this->parseElement();
        }

        // If we reached the end without finding a closing ')', throw an exception.
        if ($this->currentToken === null) {
            throw new ImapParseException('Unterminated list in response');
        }

        // Consume the closing ')' token.
        $this->advance();

        return new ListData($elements);
    }

    /**
     * Parses a single element, which might be a list or a simple token.
     */
    protected function parseElement(): Data|Token|null
    {
        // If there is no current token, return null.
        if ($this->currentToken === null) {
            return null;
        }

        // If the token indicates the start of a list, parse it as a list.
        if ($this->currentToken instanceof ListOpen) {
            return $this->parseList();
        }

        // If the token indicates the start of a group, parse it as a group.
        if ($this->currentToken instanceof ResponseCodeOpen) {
            return $this->parseBracketGroup();
        }

        // Otherwise, capture the current token.
        $token = clone $this->currentToken;

        $this->advance();

        return $token;
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
     */
    protected function isEndOfResponseToken(Token $token): bool
    {
        return $token instanceof Crlf;
    }
}
