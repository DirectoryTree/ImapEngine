<?php

namespace DirectoryTree\ImapEngine;

use Stringable;
use ZBateson\MailMimeParser\Header\HeaderConsts;
use ZBateson\MailMimeParser\Message as MailMimeMessage;

class Message implements Stringable
{
    /**
     * The parsed message.
     */
    protected ?MailMimeMessage $parsed = null;

    /**
     * Constructor.
     */
    public function __construct(
        protected Folder $folder,
        protected int $uid,
        protected array $flags,
        protected string $headers,
        protected string $contents,
        protected int $sequence = Imap::ST_MSGN
    ) {}

    public function uid(): int
    {
        return $this->uid;
    }

    public function flags(): array
    {
        return $this->flags;
    }

    public function headers(): string
    {
        return $this->headers;
    }

    public function contents(): string
    {
        return $this->contents;
    }

    public function sequence(): int
    {
        return $this->sequence;
    }

    public function from(): Address
    {
        return $this->parse()
            ->getHeader(HeaderConsts::FROM)
            ->getParts();
    }

    protected function getFirstHeader() {}

    /**
     * Parse the message.
     */
    protected function parse(): MailMimeMessage
    {
        return $this->parsed ??= MailMimeMessage::from((string) $this, true);
    }

    /**
     * Get the string representation of the message.
     */
    public function __toString(): string
    {
        return rtrim($this->headers)."\r\n\r\n".ltrim($this->contents);
    }
}
