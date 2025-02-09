<?php

namespace DirectoryTree\ImapEngine\Connection;

use Stringable;

class ImapCommand implements Stringable
{
    /**
     * The compiled command lines.
     *
     * @var string[]
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
     * Build the command lines for transmission.
     *
     * This method returns an array of strings. Literal tokens (arrays)
     * are split into a separate line so that the client can wait for
     * the server's continuation response.
     *
     * @return string[]
     */
    public function compile(): array
    {
        if ($this->compiled) {
            return $this->compiled;
        }

        $lines = [];

        $base = trim(($this->tag ?? '').' '.$this->command);

        foreach ($this->tokens as $token) {
            if (is_array($token)) {
                // When a token is an array, the first element is a
                // placeholder that triggers a literal (e.g. "{20}").
                // The literal data is in the second element.
                $lines[] = $base.' '.$token[0];

                // Replace base with the literal data to be sent as the next line.
                $base = $token[1];
            } else {
                $base .= ' '.$token;
            }
        }

        $lines[] = $base;

        return $lines;
    }

    /**
     * Get the command as a string.
     */
    public function __toString(): string
    {
        return implode("\r\n", $this->compile());
    }
}
