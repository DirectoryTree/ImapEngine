<?php

namespace DirectoryTree\ImapEngine\Connection;

class ImapToken
{
    // This covers general “words” or “atoms” in IMAP syntax, i.e. unquoted
    // text without special characters or delimiters (e.g. FLAGS, 123, INBOX).
    public const TYPE_ATOM = 'atom';

    // Nested structures (parenthesized lists) become TYPE_LIST tokens,
    // containing an array of tokens within them, which may themselves
    // be atoms, quoted strings, other lists, etc.
    public const TYPE_LIST = 'list';

    // These tokens represent blocks of data with a specified byte length,
    // enclosed in braces, e.g. {123}, followed by exactly 123 bytes of data.
    public const TYPE_LITERAL = 'literal';

    // This is for IMAP tokens wrapped in double quotes, potentially containing
    // escaped characters. The parser extracts the unescaped inner text.
    public const TYPE_QUOTED_STRING = 'quoted_string';

    /**
     * Constructor.
     */
    public function __construct(
        protected string $type,
        protected mixed $value
    ) {}

    /**
     * Get the token's type.
     */
    public function type(): string
    {
        return $this->type;
    }

    /**
     * Get the token's value.
     */
    public function value(): mixed
    {
        return $this->value;
    }
}
