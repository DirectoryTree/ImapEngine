<?php

namespace DirectoryTree\ImapEngine\Connection;

use InvalidArgumentException;
use Stringable;

class ImapCommand implements Stringable
{
    /**
     * The compiled command lines.
     *
     * @var ImapCommandLine[]|null
     */
    protected ?array $compiled = null;

    /**
     * Constructor.
     */
    public function __construct(
        protected string $tag,
        protected string $command,
        protected array $tokens = [],
    ) {}

    /**
     * Get the IMAP tag.
     */
    public function tag(): string
    {
        return $this->tag;
    }

    /**
     * Get the IMAP command.
     */
    public function command(): string
    {
        return $this->command;
    }

    /**
     * Get the IMAP tokens.
     */
    public function tokens(): array
    {
        return $this->tokens;
    }

    /**
     * Compile the command into lines for transmission.
     *
     * @return ImapCommandLine[]
     */
    public function compile(): array
    {
        if (is_array($this->compiled)) {
            return $this->compiled;
        }

        $lines = [];

        $line = trim(new CommandPart($this->tag).' '.new CommandPart($this->command));

        foreach ($this->tokens as $token) {
            if (is_array($token)) {
                // For tokens provided as arrays, the first element is a placeholder
                // (for example, "{20}") that signals a literal value will follow.
                // The second element holds the actual literal content.
                if (count($token) !== 2 || ! is_string($token[0]) || ! is_string($token[1])) {
                    throw new InvalidArgumentException('Invalid IMAP literal token.');
                }

                [$length, $literal] = $token;

                $length = (string) new CommandPart($length);

                if (! preg_match('/\A\(?\{\d+\+?\}\z/', $length)) {
                    throw new InvalidArgumentException('Invalid IMAP literal marker.');
                }

                $lines[] = new ImapCommandLine(
                    value: "{$line} {$length}",
                    synchronizing: ! str_contains($length, '+'),
                );

                $line = $literal;
            } else {
                $part = (string) new CommandPart($token);

                $line .= (str_starts_with($part, ')') ? '' : ' ').$part;
            }
        }

        $lines[] = new ImapCommandLine($line);

        return $this->compiled = $lines;
    }

    /**
     * Get a redacted version of the command for safe exposure.
     */
    public function redacted(): ImapCommand
    {
        return new static($this->tag, $this->command, array_map(
            function (mixed $token) {
                return is_array($token)
                    ? [$token[0], '[redacted]']
                    : '[redacted]';
            }, $this->tokens)
        );
    }

    /**
     * Get the command as a string.
     */
    public function __toString(): string
    {
        return implode("\r\n", $this->compile());
    }
}
