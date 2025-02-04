<?php

namespace DirectoryTree\ImapEngine;

use Carbon\Carbon;
use Stringable;
use ZBateson\MailMimeParser\Header\HeaderConsts;
use ZBateson\MailMimeParser\Header\IHeader;
use ZBateson\MailMimeParser\Header\Part\AddressPart;
use ZBateson\MailMimeParser\Message as MailMimeMessage;
use ZBateson\MailMimeParser\Message\MimePart;

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
    ) {}

    /**
     * Get the message's identifier.
     */
    public function uid(): int
    {
        return $this->uid;
    }

    /**
     * Get the message's flags.
     */
    public function flags(): array
    {
        return $this->flags;
    }

    /**
     * Get the message's headers.
     */
    public function headers(): string
    {
        return $this->headers;
    }

    /**
     * Get the message's contents.
     */
    public function contents(): string
    {
        return $this->contents;
    }

    /**
     * Get the message's sequence number.
     */
    public function sequence(): int
    {
        return $this->sequence;
    }

    /**
     * Get the message date.
     */
    public function date(): ?Carbon
    {
        if ($date = $this->header(HeaderConsts::DATE)?->getDateTime()) {
            return Carbon::instance($date);
        }

        return null;
    }

    /**
     * Get the message's message-id.
     */
    public function messageId(): ?string
    {
        return $this->header(HeaderConsts::MESSAGE_ID)?->getValue();
    }

    /**
     * Get the message's subject.
     */
    public function subject(): ?string
    {
        return $this->header(HeaderConsts::SUBJECT)?->getValue();
    }

    /**
     * Get the FROM address.
     */
    public function from(): ?Address
    {
        return head($this->addresses(HeaderConsts::FROM));
    }

    /**
     * Get the SENDER address.
     */
    public function sender(): ?Address
    {
        return head($this->addresses(HeaderConsts::SENDER));
    }

    /**
     * Get the REPLY-TO address.
     */
    public function replyTo(): ?Address
    {
        return head($this->addresses(HeaderConsts::REPLY_TO));
    }

    /**
     * Get the IN-REPLY-TO address.
     */
    public function inReplyTo(): ?Address
    {
        return head($this->addresses(HeaderConsts::IN_REPLY_TO));
    }

    /**
     * Get the TO addresses.
     *
     * @return Address[]
     */
    public function to(): array
    {
        return $this->addresses(HeaderConsts::TO);
    }

    /**
     * Get the CC addresses.
     *
     * @return Address[]
     */
    public function cc(): array
    {
        return $this->addresses(HeaderConsts::CC);
    }

    /**
     * Get the BCC addresses.
     *
     * @return Address[]
     */
    public function bcc(): array
    {
        return $this->addresses(HeaderConsts::BCC);
    }

    /**
     * Get the message's attachments.
     *
     * @return Attachment[]
     */
    public function attachments(): array
    {
        return array_map(function (MimePart $part) {
            return new Attachment(
                $part->getFilename(),
                $part->getContentType(),
                $part->getContentStream(),
            );
        }, $this->parse()->getAllAttachmentParts());
    }

    /**
     * Determine if the message has attachments.
     */
    public function hasAttachments(): bool
    {
        return $this->attachmentCount() > 0;
    }

    /**
     * Get the count of attachments.
     */
    public function attachmentCount(): int
    {
        return $this->parse()->getAttachmentCount();
    }

    /**
     * Get addresses from the given header.
     *
     * @return Address[]
     */
    public function addresses(string $header): array
    {
        return array_map(function (AddressPart $part) {
            return new Address($part->getEmail(), $part->getName());
        }, $this->header($header)?->getParts() ?? []);
    }

    /**
     * Get the message's HTML content.
     */
    public function html(): ?string
    {
        return $this->parse()->getHtmlContent();
    }

    /**
     * Get the message's text content.
     */
    public function text(): ?string
    {
        return $this->parse()->getTextContent();
    }

    /**
     * Get a header from the message.
     */
    public function header(string $name, int $offset = 0): ?IHeader
    {
        return $this->parse()->getHeader($name, $offset);
    }

    /**
     * Parse the message into a MailMimeMessage instance.
     */
    public function parse(): MailMimeMessage
    {
        return $this->parsed ??= MailMimeMessage::from((string) $this, true);
    }

    /**
     * Get the string representation of the message.
     */
    public function __toString(): string
    {
        return implode("\r\n\r\n", array_filter([
            rtrim($this->headers),
            ltrim($this->contents),
        ]));
    }
}
