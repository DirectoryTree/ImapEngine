<?php

namespace DirectoryTree\ImapEngine;

use DirectoryTree\ImapEngine\Connection\Responses\MessageResponseParser;
use DirectoryTree\ImapEngine\Exceptions\ImapCapabilityException;
use DirectoryTree\ImapEngine\Support\Str;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

class Message implements Arrayable, JsonSerializable, MessageInterface
{
    use HasFlags, HasParsedMessage;

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
     * {@inheritDoc}
     */
    public function is(MessageInterface $message): bool
    {
        return $message instanceof self
            && $this->uid === $message->uid
            && $this->head === $message->head
            && $this->body === $message->body
            && $this->folder->is($message->folder);
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
    public function copy(string $folder): ?int
    {
        $mailbox = $this->folder->mailbox();

        $capabilities = $mailbox->capabilities();

        if (! in_array('UIDPLUS', $capabilities)) {
            throw new ImapCapabilityException(
                'Unable to copy message. IMAP server does not support UIDPLUS capability'
            );
        }

        $response = $mailbox->connection()->copy($folder, $this->uid);

        return MessageResponseParser::getUidFromCopy($response);
    }

    /**
     * Move the message to the given folder.
     *
     * @throws ImapCapabilityException
     */
    public function move(string $folder, bool $expunge = false): ?int
    {
        $mailbox = $this->folder->mailbox();

        $capabilities = $mailbox->capabilities();

        switch (true) {
            case in_array('MOVE', $capabilities):
                $response = $mailbox->connection()->move($folder, $this->uid);

                if ($expunge) {
                    $this->folder->expunge();
                }

                return MessageResponseParser::getUidFromCopy($response);

            case in_array('UIDPLUS', $capabilities):
                $uid = $this->copy($folder);

                $this->delete($expunge);

                return $uid;

            default:
                throw new ImapCapabilityException(
                    'Unable to move message. IMAP server does not support MOVE or UIDPLUS capabilities'
                );
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
