<?php

namespace DirectoryTree\ImapEngine;

use DirectoryTree\ImapEngine\Connection\ImapQueryBuilder;
use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
use DirectoryTree\ImapEngine\Enums\ImapFetchIdentifier;
use DirectoryTree\ImapEngine\Exceptions\Exception;
use DirectoryTree\ImapEngine\Exceptions\ImapCapabilityException;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\ItemNotFoundException;
use JsonSerializable;

class Folder implements Arrayable, FolderInterface, JsonSerializable
{
    /**
     * The folder's cached capabilities.
     */
    protected array $capabilities;

    /**
     * Constructor.
     */
    public function __construct(
        protected Mailbox $mailbox,
        protected string $path,
        protected array $flags = [],
        protected string $delimiter = '/',
    ) {}

    /**
     * Get the folder's mailbox.
     */
    public function mailbox(): Mailbox
    {
        return $this->mailbox;
    }

    /**
     * Get the folder path.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Get the folder flags.
     *
     * @return string[]
     */
    public function flags(): array
    {
        return $this->flags;
    }

    /**
     * {@inheritDoc}
     */
    public function delimiter(): string
    {
        return $this->delimiter;
    }

    /**
     * {@inheritDoc}
     */
    public function name(): string
    {
        return last(explode($this->delimiter, $this->path));
    }

    /**
     * {@inheritDoc}
     */
    public function is(FolderInterface $folder): bool
    {
        return $this->path === $folder->path()
            && $this->mailbox->config('host') === $folder->mailbox()->config('host')
            && $this->mailbox->config('username') === $folder->mailbox()->config('username');
    }

    /**
     * {@inheritDoc}
     */
    public function messages(): MessageQuery
    {
        // Ensure the folder is selected.
        $this->select(true);

        return new MessageQuery($this, new ImapQueryBuilder);
    }

    /**
     * {@inheritDoc}
     */
    public function idle(callable $callback, ?callable $query = null, int $timeout = 300): void
    {
        if (! in_array('IDLE', $this->mailbox->capabilities())) {
            throw new ImapCapabilityException('IMAP server does not support IDLE');
        }

        // The message query to use when fetching messages.
        $query ??= fn (MessageQuery $query) => $query;

        // Fetch the message by message number.
        $fetch = fn (int $msgn) => (
            $query($this->messages())->findOrFail($msgn, ImapFetchIdentifier::MessageNumber)
        );

        (new Idle(clone $this->mailbox, $this->path, $timeout))->await(
            function (int $msgn) use ($callback, $fetch) {
                if (! $this->mailbox->connected()) {
                    $this->mailbox->connect();
                }

                try {
                    $message = $fetch($msgn);
                } catch (ItemNotFoundException) {
                    // The message wasn't found. We will skip
                    // it and continue awaiting new messages.
                    return;
                } catch (Exception) {
                    // Something else happened. We will attempt
                    // reconnecting and re-fetching the message.
                    $this->mailbox->reconnect();

                    $message = $fetch($msgn);
                }

                $callback($message);
            }
        );
    }

    /**
     * {@inheritDoc}
     */
    public function move(string $newPath): void
    {
        $this->mailbox->connection()->rename($this->path, $newPath);

        $this->path = $newPath;
    }

    /**
     * {@inheritDoc}
     */
    public function select(bool $force = false): void
    {
        $this->mailbox->select($this, $force);
    }

    /**
     * {@inheritDoc}
     */
    public function status(): array
    {
        $response = $this->mailbox->connection()->status($this->path);

        $tokens = $response->tokenAt(3)->tokens();

        $values = [];

        // Tokens are expected to alternate between keys and values.
        for ($i = 0; $i < count($tokens); $i += 2) {
            $values[$tokens[$i]->value] = $tokens[$i + 1]->value;
        }

        return $values;
    }

    /**
     * {@inheritDoc}
     */
    public function examine(): array
    {
        return $this->mailbox->connection()->examine($this->path)->map(
            fn (UntaggedResponse $response) => $response->toArray()
        )->all();
    }

    /**
     * {@inheritDoc}
     */
    public function expunge(): array
    {
        return $this->mailbox->connection()->expunge()->map(
            fn (UntaggedResponse $response) => $response->tokenAt(1)->value
        )->all();
    }

    /**
     * {@inheritDoc}
     */
    public function delete(): void
    {
        $this->mailbox->connection()->delete($this->path);
    }

    /**
     * Get the array representation of the folder.
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'flags' => $this->flags,
            'delimiter' => $this->delimiter,
        ];
    }

    /**
     * Get the JSON representation of the folder.
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
