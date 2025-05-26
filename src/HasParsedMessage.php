<?php

namespace DirectoryTree\ImapEngine;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use DirectoryTree\ImapEngine\Exceptions\RuntimeException;
use GuzzleHttp\Psr7\Utils;
use ZBateson\MailMimeParser\Header\HeaderConsts;
use ZBateson\MailMimeParser\Header\IHeader;
use ZBateson\MailMimeParser\Header\IHeaderPart;
use ZBateson\MailMimeParser\Header\Part\AddressPart;
use ZBateson\MailMimeParser\Header\Part\ContainerPart;
use ZBateson\MailMimeParser\Header\Part\NameValuePart;
use ZBateson\MailMimeParser\Message as MailMimeMessage;

trait HasParsedMessage
{
    /**
     * The parsed message.
     */
    protected ?MailMimeMessage $parsed = null;

    /**
     * Get the message date and time.
     */
    public function date(): ?CarbonInterface
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
        return head($this->addresses(HeaderConsts::FROM)) ?: null;
    }

    /**
     * Get the SENDER address.
     */
    public function sender(): ?Address
    {
        return head($this->addresses(HeaderConsts::SENDER)) ?: null;
    }

    /**
     * Get the REPLY-TO address.
     */
    public function replyTo(): ?Address
    {
        return head($this->addresses(HeaderConsts::REPLY_TO)) ?: null;
    }

    /**
     * Get the IN-REPLY-TO address.
     */
    public function inReplyTo(): ?Address
    {
        return head($this->addresses(HeaderConsts::IN_REPLY_TO)) ?: null;
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
        $attachments = [];

        foreach ($this->parse()->getAllAttachmentParts() as $part) {
            // If the attachment's content type is message/rfc822, we're
            // working with a forwarded message. We will parse the
            // forwarded message and merge in its attachments.
            if (strtolower($part->getContentType()) === 'message/rfc822') {
                $message = new FileMessage($part->getContent());

                $attachments = array_merge($attachments, $message->attachments());
            } else {
                $attachments[] = new Attachment(
                    $part->getFilename(),
                    $part->getContentId(),
                    $part->getContentType(),
                    $part->getBinaryContentStream() ?? Utils::streamFor(''),
                );
            }
        }

        return $attachments;
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
        $parts = $this->header($header)?->getParts() ?? [];

        $addresses = array_map(fn (IHeaderPart $part) => match (true) {
            $part instanceof AddressPart => new Address($part->getEmail(), $part->getName()),
            $part instanceof NameValuePart => new Address($part->getName(), $part->getValue()),
            $part instanceof ContainerPart => new Address($part->getValue(), ''),
            default => null,
        }, $parts);

        return array_filter($addresses);
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
     * Get all headers from the message.
     */
    public function headers(): array
    {
        return $this->parse()->getAllHeaders();
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
        if ($this->isEmpty()) {
            throw new RuntimeException('Cannot parse an empty message');
        }

        return $this->parsed ??= MessageParser::parse((string) $this);
    }

    /**
     * Determine if the message is empty.
     */
    abstract protected function isEmpty(): bool;

    /**
     * Get the string representation of the message.
     */
    abstract public function __toString(): string;
}
