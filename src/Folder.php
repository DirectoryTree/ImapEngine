<?php

namespace DirectoryTree\ImapEngine;

use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
use DirectoryTree\ImapEngine\Exceptions\ResponseException;
use DirectoryTree\ImapEngine\Exceptions\RuntimeException;

class Folder
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
     * Get the folder delimiter.
     */
    public function delimiter(): string
    {
        return $this->delimiter;
    }

    /**
     * Get the folder name.
     */
    public function name(): string
    {
        return last(explode($this->delimiter, $this->path));
    }

    /**
     * Determine if the folder is the same as the given folder.
     */
    public function is(Folder $folder): bool
    {
        return $this->path === $folder->path
            && $this->mailbox->config('host') === $folder->mailbox->config('host')
            && $this->mailbox->config('username') === $folder->mailbox->config('username');
    }

    /**
     * Begin querying for messages.
     */
    public function messages(): MessageQuery
    {
        return new MessageQuery(
            tap($this)->select(true)
        );
    }

    /**
     * Begin idling on the current folder.
     */
    public function idle(callable $callback, int $timeout = 300): void
    {
        if (! $this->hasIdleSupport()) {
            throw new RuntimeException('IMAP server does not support IDLE');
        }

        $fetch = function (int $msgn) {
            return $this->messages()->find($msgn, Imap::SEQUENCE_TYPE_MSG_NUMBER);
        };

        (new Idle(clone $this->mailbox, $this->path, $timeout))->await(
            function (int $msgn) use ($callback, $fetch) {
                // Connect the client if the connection is closed.
                if ($this->mailbox->isClosed()) {
                    $this->mailbox->connect();
                }

                try {
                    $message = $fetch($msgn);
                } catch (RuntimeException|ResponseException) {
                    // If fetching the message fails, we'll attempt
                    // reconnecting and re-fetching the message.
                    $this->mailbox->reconnect();

                    $message = $fetch($msgn);
                }

                $callback($message);
            }
        );
    }

    /**
     * Move or rename the current folder.
     */
    public function move(string $newPath, bool $expunge = true): array
    {
        $status = $this->mailbox->connection()
            ->renameFolder($this->path, $newPath)
            ->getValidatedData();

        if ($expunge) {
            $this->expunge();
        }

        $this->path = $newPath;

        return $status;
    }

    /**
     * Delete the current folder.
     */
    public function delete(bool $expunge = true): array
    {
        $this->mailbox->connection()->deleteFolder($this->path);

        if ($expunge) {
            return $this->expunge();
        }

        return [];
    }

    /**
     * Select the current folder.
     */
    public function select(bool $force = false): void
    {
        $this->mailbox->select($this, $force);
    }

    /**
     * Get the folder's status.
     */
    public function status(): array
    {
        $response = $this->mailbox->connection()->folderStatus($this->path);

        $tokens = $response->tokenAt(3)->tokens();

        $values = [];

        // Tokens are expected to alternate between keys and values.
        for ($i = 0; $i < count($tokens); $i += 2) {
            $values[$tokens[$i]->value] = $tokens[$i + 1]->value;
        }

        return $values;
    }

    /**
     * Examine the current folder and get detailed status information.
     */
    public function examine(): array
    {
        return $this->mailbox->connection()->examineFolder($this->path)->map(
            fn (UntaggedResponse $response) => $response->toArray()
        );
    }

    /**
     * Expunge the mailbox and return the expunged message sequence numbers.
     */
    public function expunge(): array
    {
        return $this->mailbox->connection()->expunge()->map(
            fn (UntaggedResponse $response) => $response->tokenAt(1)->value
        );
    }

    /**
     * Determine if the mailbox has IDLE support.
     */
    protected function hasIdleSupport(): bool
    {
        return in_array('IDLE', $this->capabilities());
    }

    /**
     * Get the mailboxes's capabilities.
     */
    protected function capabilities(): array
    {
        return $this->capabilities ??= $this->mailbox->capabilities();
    }
}
