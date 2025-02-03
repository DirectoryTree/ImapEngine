<?php

namespace DirectoryTree\ImapEngine\Connection;

class ImapCommand
{
    /**
     * The IMAP command name.
     */
    protected string $command;

    /**
     * The IMAP command tokens.
     */
    protected array $tokens;

    /**
     * The IMAP command tag.
     */
    protected ?string $tag = null;

    /**
     * Constructor.
     *
     * @param  string  $command  The IMAP command name (e.g. 'LOGIN', 'FETCH', etc.)
     * @param  array  $tokens  Any additional parameters for the command.
     */
    public function __construct(string $command, array $tokens = [])
    {
        $this->command = $command;
        $this->tokens = $tokens;
    }

    /**
     * Set the tag for this command.
     */
    public function setTag(string $tag): self
    {
        $this->tag = $tag;

        return $this;
    }

    /**
     * Get the command tag.
     */
    public function getTag(): ?string
    {
        return $this->tag;
    }

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
}
