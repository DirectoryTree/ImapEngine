<?php

namespace DirectoryTree\ImapEngine;

use Carbon\Carbon;
use DirectoryTree\ImapEngine\Exceptions\RuntimeException;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Stringable;
use ZBateson\MailMimeParser\Header\HeaderConsts;
use ZBateson\MailMimeParser\Header\IHeader;
use ZBateson\MailMimeParser\Header\Part\AddressPart;
use ZBateson\MailMimeParser\Message as MailMimeMessage;
use ZBateson\MailMimeParser\Message\MimePart;

class Message implements Arrayable, JsonSerializable, Stringable
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
     * Get the names of properties that should be serialized.
     */
    public function __sleep(): array
    {
        return ['folder', 'uid', 'flags', 'headers', 'contents'];
    }

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
     * Get the message's raw headers.
     */
    public function headers(): string
    {
        return $this->headers;
    }

    /**
     * Determine if the message has headers.
     */
    public function hasHeaders(): bool
    {
        return ! empty($this->headers);
    }

    /**
     * Get the message's raw contents.
     */
    public function contents(): string
    {
        return $this->contents;
    }

    /**
     * Determine if the message has contents.
     */
    public function hasContents(): bool
    {
        return ! empty($this->contents);
    }

    /**
     * Get the message date and time.
     */
    public function dateTime(): ?Carbon
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
     * Check if the message is marked as seen.
     */
    public function isSeen(): bool
    {
        return in_array('\Seen', $this->flags);
    }

    /**
     * Check if the message is marked as answered.
     */
    public function isAnswered(): bool
    {
        return in_array('\Answered', $this->flags);
    }

    /**
     * Check if the message is flagged.
     */
    public function isFlagged(): bool
    {
        return in_array('\Flagged', $this->flags);
    }

    /**
     * Check if the message is marked as deleted.
     */
    public function isDeleted(): bool
    {
        return in_array('\Deleted', $this->flags);
    }

    /**
     * Check if the message is marked as a draft.
     */
    public function isDraft(): bool
    {
        return in_array('\Draft', $this->flags);
    }

    /**
     * Check if the message is marked as recent.
     */
    public function isRecent(): bool
    {
        return in_array('\Recent', $this->flags);
    }

    /**
     * Get a header from the message.
     */
    public function header(string $name, int $offset = 0): ?IHeader
    {
        return $this->parse()->getHeader($name, $offset);
    }

    /**
     * Mark the message as read. Alias for markSeen.
     */
    public function markRead(bool $expunge = true): void
    {
        $this->markSeen($expunge);
    }

    /**
     * Mark the message as unread. Alias for unmarkSeen.
     */
    public function markUnread(bool $expunge = true): void
    {
        $this->unmarkSeen($expunge);
    }

    /**
     * Mark the message as seen.
     */
    public function markSeen(bool $expunge = true): void
    {
        $this->flag('Seen', '+', $expunge);
    }

    /**
     * Unmark the seen flag.
     */
    public function unmarkSeen(bool $expunge = true): void
    {
        $this->flag('Seen', '-', $expunge);
    }

    /**
     * Mark the message as answered.
     */
    public function markAnswered(bool $expunge = true): void
    {
        $this->flag('Answered', '+', $expunge);
    }

    /**
     * Unmark the answered flag.
     */
    public function unmarkAnswered(bool $expunge = true): void
    {
        $this->flag('Answered', '-', $expunge);
    }

    /**
     * Mark the message as flagged.
     */
    public function markFlagged(bool $expunge = true): void
    {
        $this->flag('Flagged', '+', $expunge);
    }

    /**
     * Unmark the flagged flag.
     */
    public function unmarkFlagged(bool $expunge = true): void
    {
        $this->flag('Flagged', '-', $expunge);
    }

    /**
     * Mark the message as deleted.
     */
    public function markDeleted(bool $expunge = true): void
    {
        $this->flag('Deleted', '+', $expunge);
    }

    /**
     * Unmark the deleted flag.
     */
    public function unmarkDeleted(bool $expunge = true): void
    {
        $this->flag('Deleted', '-', $expunge);
    }

    /**
     * Mark the message as a draft.
     */
    public function markDraft(bool $expunge = true): void
    {
        $this->flag('Draft', '+', $expunge);
    }

    /**
     * Unmark the draft flag.
     */
    public function unmarkDraft(bool $expunge = true): void
    {
        $this->flag('Draft', '-', $expunge);
    }

    /**
     * Mark the message as recent.
     */
    public function markRecent(bool $expunge = true): void
    {
        $this->flag('Recent', '+', $expunge);
    }

    /**
     * Unmark the recent flag.
     */
    public function unmarkRecent(bool $expunge = true): void
    {
        $this->flag('Recent', '-', $expunge);
    }

    /**
     * Add or remove a flag from the message.
     */
    public function flag(array|string $flag, string $operation = '+', bool $expunge = true): void
    {
        $flag = '\\'.trim(is_array($flag) ? implode(' \\', $flag) : $flag);

        $this->folder->mailbox()
            ->connection()
            ->store([$flag], $this->uid, null, $operation, true);

        if ($expunge) {
            $this->folder->expunge();
        }
    }

    /**
     * Copy the message to the given folder.
     */
    public function copy(string $folder, bool $expunge = true): void
    {
        $this->folder->mailbox()
            ->connection()
            ->copy($folder, $this->folder->path(), $this->uid);

        if ($expunge) {
            $this->folder->expunge();
        }
    }

    /**
     * Move the message to the given folder.
     */
    public function move(string $folder, bool $expunge = true): void
    {
        $this->folder->mailbox()
            ->connection()
            ->move($folder, $this->folder->path(), $this->uid);

        if ($expunge) {
            $this->folder->expunge();
        }
    }

    /**
     * Delete the message.
     */
    public function delete(bool $expunge = true): void
    {
        $this->markDeleted();

        if ($expunge) {
            $this->folder->expunge();
        }
    }

    /**
     * Parse the message into a MailMimeMessage instance.
     */
    public function parse(): MailMimeMessage
    {
        if (! $this->hasHeaders() && ! $this->hasContents()) {
            throw new RuntimeException('Cannot parse an empty message');
        }

        return $this->parsed ??= MessageParser::parse((string) $this);
    }

    /**
     * Get the array representation of the message.
     */
    public function toArray(): array
    {
        return [
            'uid' => $this->uid,
            'flags' => $this->flags,
            'headers' => $this->headers,
            'contents' => $this->contents,
        ];
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

    /**
     * Get the JSON representation of the message.
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
