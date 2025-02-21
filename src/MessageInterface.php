<?php

namespace DirectoryTree\ImapEngine;

use Carbon\Carbon;
use Stringable;
use ZBateson\MailMimeParser\Header\IHeader;
use ZBateson\MailMimeParser\Message as MailMimeMessage;

interface MessageInterface extends Stringable
{
    /**
     * Get the message date and time.
     */
    public function date(): ?Carbon;

    /**
     * Get the message's subject.
     */
    public function subject(): ?string;

    /**
     * Get the 'From' address.
     */
    public function from(): ?Address;

    /**
     * Get the 'Sender' address.
     */
    public function sender(): ?Address;

    /**
     * Get the message's 'Message-ID'.
     */
    public function messageId(): ?string;

    /**
     * Get the 'Reply-To' address.
     */
    public function replyTo(): ?Address;

    /**
     * Get the 'In-Reply-To' address.
     */
    public function inReplyTo(): ?Address;

    /**
     * Get the 'To' addresses.
     *
     * @return Address[]
     */
    public function to(): array;

    /**
     * Get the 'CC' addresses.
     *
     * @return Address[]
     */
    public function cc(): array;

    /**
     * Get the 'BCC' addresses.
     *
     * @return Address[]
     */
    public function bcc(): array;

    /**
     * Get the message's attachments.
     *
     * @return Attachment[]
     */
    public function attachments(): array;

    /**
     * Determine if the message has attachments.
     */
    public function hasAttachments(): bool;

    /**
     * Get the count of attachments.
     */
    public function attachmentCount(): int;

    /**
     * Get addresses from the given header.
     *
     * @return Address[]
     */
    public function addresses(string $header): array;

    /**
     * Get the message's HTML content.
     */
    public function html(): ?string;

    /**
     * Get the message's text content.
     */
    public function text(): ?string;

    /**
     * Get all headers from the message.
     */
    public function headers(): array;

    /**
     * Get a header from the message.
     */
    public function header(string $name, int $offset = 0): ?IHeader;

    /**
     * Parse the message into a MailMimeMessage instance.
     */
    public function parse(): MailMimeMessage;

    /**
     * Get the string representation of the message.
     */
    public function __toString(): string;
}
