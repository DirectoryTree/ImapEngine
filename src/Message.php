<?php

namespace DirectoryTree\ImapEngine;

use BackedEnum;
use Carbon\Carbon;
use DirectoryTree\ImapEngine\Enums\ImapFlag;
use DirectoryTree\ImapEngine\Exceptions\RuntimeException;
use DirectoryTree\ImapEngine\Support\Str;
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
        protected string $head,
        protected string $body,
    ) {}

    /**
     * Get the names of properties that should be serialized.
     */
    public function __sleep(): array
    {
        // We don't want to serialize the parsed message.
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
    public function head(): string
    {
        return $this->head;
    }

    /**
     * Determine if the message has headers.
     */
    public function hasHead(): bool
    {
        return ! empty($this->head);
    }

    /**
     * Get the message's raw body.
     */
    public function body(): string
    {
        return $this->body;
    }

    /**
     * Determine if the message has contents.
     */
    public function hasBody(): bool
    {
        return ! empty($this->body);
    }

    /**
     * Get the message date and time.
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
     * Determine if the message is marked as seen.
     */
    public function isSeen(): bool
    {
        return $this->hasFlag(ImapFlag::Seen);
    }

    /**
     * Determine if the message is marked as answered.
     */
    public function isAnswered(): bool
    {
        return $this->hasFlag(ImapFlag::Answered);
    }

    /**
     * Determine if the message is flagged.
     */
    public function isFlagged(): bool
    {
        return $this->hasFlag(ImapFlag::Flagged);
    }

    /**
     * Determine if the message is marked as deleted.
     */
    public function isDeleted(): bool
    {
        return $this->hasFlag(ImapFlag::Deleted);
    }

    /**
     * Determine if the message is marked as a draft.
     */
    public function isDraft(): bool
    {
        return $this->hasFlag(ImapFlag::Draft);
    }

    /**
     * Determine if the message is marked as recent.
     */
    public function isRecent(): bool
    {
        return $this->hasFlag(ImapFlag::Recent);
    }

    /**
     * Determine if the message has the given flag.
     */
    public function hasFlag(BackedEnum|string $flag): bool
    {
        return in_array(Str::enum($flag), $this->flags);
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
     * Mark the message as read. Alias for markSeen.
     */
    public function markRead(): void
    {
        $this->markSeen();
    }

    /**
     * Mark the message as unread. Alias for unmarkSeen.
     */
    public function markUnread(): void
    {
        $this->unmarkSeen();
    }

    /**
     * Mark the message as seen.
     */
    public function markSeen(): void
    {
        $this->flag(ImapFlag::Seen, '+');
    }

    /**
     * Unmark the seen flag.
     */
    public function unmarkSeen(): void
    {
        $this->flag(ImapFlag::Seen, '-');
    }

    /**
     * Mark the message as answered.
     */
    public function markAnswered(): void
    {
        $this->flag(ImapFlag::Answered, '+');
    }

    /**
     * Unmark the answered flag.
     */
    public function unmarkAnswered(): void
    {
        $this->flag(ImapFlag::Answered, '-');
    }

    /**
     * Mark the message as flagged.
     */
    public function markFlagged(): void
    {
        $this->flag(ImapFlag::Flagged, '+');
    }

    /**
     * Unmark the flagged flag.
     */
    public function unmarkFlagged(): void
    {
        $this->flag(ImapFlag::Flagged, '-');
    }

    /**
     * Mark the message as deleted.
     */
    public function markDeleted(bool $expunge = false): void
    {
        $this->flag(ImapFlag::Deleted, '+', $expunge);
    }

    /**
     * Unmark the deleted flag.
     */
    public function unmarkDeleted(): void
    {
        $this->flag(ImapFlag::Deleted, '-');
    }

    /**
     * Mark the message as a draft.
     */
    public function markDraft(): void
    {
        $this->flag(ImapFlag::Draft, '+');
    }

    /**
     * Unmark the draft flag.
     */
    public function unmarkDraft(): void
    {
        $this->flag(ImapFlag::Draft, '-');
    }

    /**
     * Mark the message as recent.
     */
    public function markRecent(): void
    {
        $this->flag(ImapFlag::Recent, '+');
    }

    /**
     * Unmark the recent flag.
     */
    public function unmarkRecent(): void
    {
        $this->flag(ImapFlag::Recent, '-');
    }

    /**
     * Add or remove a flag from the message.
     */
    public function flag(mixed $flag, string $operation, bool $expunge = false): void
    {
        $flag = Str::enum($flag);

        $this->folder->mailbox()
            ->connection()
            ->store($flag, $this->uid, mode: $operation);

        if ($expunge) {
            $this->folder->expunge();
        }

        $this->flags = match ($operation) {
            '+' => array_unique(array_merge($this->flags, [$flag])),
            '-' => array_diff($this->flags, [$flag]),
        };
    }

    /**
     * Copy the message to the given folder.
     */
    public function copy(string $folder): void
    {
        $this->folder->mailbox()
            ->connection()
            ->copy($folder, $this->uid);
    }

    /**
     * Move the message to the given folder.
     */
    public function move(string $folder, bool $expunge = false): void
    {
        $this->folder->mailbox()
            ->connection()
            ->move($folder, $this->uid);

        if ($expunge) {
            $this->folder->expunge();
        }
    }

    /**
     * Delete the message.
     */
    public function delete(bool $expunge = false): void
    {
        $this->markDeleted($expunge);
    }

    /**
     * Restore the message.
     */
    public function restore(): void
    {
        $this->unmarkDeleted();
    }

    /**
     * Parse the message into a MailMimeMessage instance.
     */
    public function parse(): MailMimeMessage
    {
        if (! $this->hasHead() && ! $this->hasBody()) {
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
            'head' => $this->head,
            'body' => $this->body,
        ];
    }

    /**
     * Get the string representation of the message.
     */
    public function __toString(): string
    {
        return implode("\r\n\r\n", array_filter([
            rtrim($this->head),
            ltrim($this->body),
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
