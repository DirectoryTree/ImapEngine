<?php

namespace DirectoryTree\ImapEngine;

use BackedEnum;
use DirectoryTree\ImapEngine\Enums\ImapFlag;
use DirectoryTree\ImapEngine\Support\Str;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

class Message implements Arrayable, JsonSerializable, MessageInterface
{
    use HasParsedMessage;

    /**
     * Constructor.
     */
    public function __construct(
        protected FolderInterface $folder,
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
     * Get the message's folder.
     */
    public function folder(): FolderInterface
    {
        return $this->folder;
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

    /**
     * Determine if the message is empty.
     */
    protected function isEmpty(): bool
    {
        return ! $this->hasHead() && ! $this->hasBody();
    }
}
