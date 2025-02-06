<?php

namespace DirectoryTree\ImapEngine\Connection;

use DirectoryTree\ImapEngine\Connection\Data\Data;
use DirectoryTree\ImapEngine\Connection\Data\GroupData;
use DirectoryTree\ImapEngine\Connection\Data\ListData;
use DirectoryTree\ImapEngine\Connection\Responses\ContinuationResponse;
use DirectoryTree\ImapEngine\Connection\Responses\Response;
use DirectoryTree\ImapEngine\Connection\Responses\TaggedResponse;
use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
use DirectoryTree\ImapEngine\Connection\Tokens\Atom;
use DirectoryTree\ImapEngine\Connection\Tokens\Crlf;
use DirectoryTree\ImapEngine\Connection\Tokens\GroupClose;
use DirectoryTree\ImapEngine\Connection\Tokens\GroupOpen;
use DirectoryTree\ImapEngine\Connection\Tokens\ListClose;
use DirectoryTree\ImapEngine\Connection\Tokens\ListOpen;
use DirectoryTree\ImapEngine\Connection\Tokens\Literal;
use DirectoryTree\ImapEngine\Connection\Tokens\QuotedString;
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

        $this->advance(); // Load the first token.
    }

    /**
     * Parse an IMAP response.
     *
     * This method dispatches to specialized methods based on the type
     * and value of the current token. It expects that the tokenizer
     * will eventually emit an end-of-response marker (CRLF).
     *
     * @throws ImapParseException if the response is empty.
     */
    public function parse(): Data|Token|Response
    {
        if ($this->currentToken === null) {
            throw new ImapParseException('Empty response');
        }

        // If the current token is a quoted string or a literal,
        // return it directly as a simple element.
        if ($this->currentToken instanceof QuotedString || $this->currentToken instanceof Literal) {
            return $this->parseElement();
        }

        // If the token indicates the beginning of a list, delegate to parseList().
        if ($this->currentToken instanceof ListOpen) {
            return $this->parseList();
        }

        // If the token is an ATOM, check its value for special markers.
        if ($this->currentToken instanceof Atom) {
            $token = clone $this->currentToken;

            // '*' marks an untagged response.
            if ($token->value === '*') {
                return $this->parseUntaggedResponse();
            }

            // '+' marks a continuation response.
            if ($token->value === '+') {
                return $this->parseContinuationResponse();
            }
        }

        // If none of the above, assume it's a tagged response.
        return $this->parseTaggedResponse();
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

        // Loop to collect the rest of the tokens for this response until we reach CRLF.
        while ($this->currentToken !== null && ! $this->isEndOfResponseToken($this->currentToken)) {
            $elements[] = $this->parseElement();
        }

        // If the end-of-response marker (CRLF) is present, consume it.
        if ($this->currentToken !== null && $this->isEndOfResponseToken($this->currentToken)) {
            $this->advance();
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
        while ($this->currentToken !== null && ! $this->isEndOfResponseToken($this->currentToken)) {
            $elements[] = $this->parseElement();
        }

        // Consume the CRLF marker if present.
        if ($this->currentToken !== null && $this->isEndOfResponseToken($this->currentToken)) {
            $this->advance();
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
        while ($this->currentToken !== null && ! $this->isEndOfResponseToken($this->currentToken)) {
            $tokens[] = $this->parseElement();
        }

        // Consume the CRLF marker if present.
        if ($this->currentToken !== null && $this->isEndOfResponseToken($this->currentToken)) {
            $this->advance();
        }

        return new TaggedResponse($tokens);
    }

    /**
     * Parses a bracket group of elements delimited by '[' and ']'.
     *
     * @throws ImapParseException if the group is unterminated.
     */
    protected function parseBracketGroup(): GroupData
    {
        // Consume the opening '[' token.
        $this->advance();

        $elements = [];

        while (
            $this->currentToken !== null
            && ! $this->currentToken instanceof GroupClose
            && ! $this->isEndOfResponseToken($this->currentToken)
        ) {
            $elements[] = $this->parseElement();
        }

        if ($this->currentToken === null) {
            throw new ImapParseException('Unterminated bracket group in response');
        }

        // Consume the closing ']' token.
        $this->advance();

        return new GroupData($elements);
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
            $this->currentToken !== null
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
        if ($this->currentToken instanceof GroupOpen) {
            return $this->parseBracketGroup();
        }

        // Otherwise, capture the value of the token.
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
