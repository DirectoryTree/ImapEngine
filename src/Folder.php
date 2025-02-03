<?php

namespace DirectoryTree\ImapEngine;

use DirectoryTree\ImapEngine\Exceptions\ResponseException;
use DirectoryTree\ImapEngine\Exceptions\RuntimeException;

class Folder
{
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
        return new MessageQuery($this);
    }

    /**
     * Begin idling on the current folder.
     */
    public function idle(callable $callback, int $timeout = 300): void
    {
        if (! $this->hasIdleSupport()) {
            throw new RuntimeException('IMAP server does not support IDLE');
        }

        $fetch = function (int $msgn, int $sequence) {
            // Always reopen the folder on the main client.
            // Otherwise, the new message number isn't
            // known to the current remote session.
            $this->select(true);

            $message = $this->messages()->getMessageByMsgn($msgn);

            $message->setSequence($sequence);

            return $message;
        };

        (new Idle(clone $this->mailbox, $this->path, $timeout))->await(
            function (int $msgn, int $sequence) use ($callback, $fetch) {
                // Connect the client if the connection is closed.
                if ($this->mailbox->isClosed()) {
                    $this->mailbox->connect();
                }

                try {
                    $message = $fetch($msgn, $sequence);
                } catch (RuntimeException|ResponseException) {
                    // If fetching the message fails, we'll attempt
                    // reconnecting and re-fetching the message.
                    $this->mailbox->reconnect();

                    $message = $fetch($msgn, $sequence);
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
        $status = $this->mailbox->connection()
            ->deleteFolder($this->path)
            ->getValidatedData();

        if ($expunge) {
            $this->expunge();
        }

        return $status;
    }

    /**
     * Select the current folder.
     */
    public function select(bool $force = false): void
    {
        $this->mailbox->select($this, $force);
    }

    /**
     * Get the folder's flags.
     */
    public function status(): array
    {
        return $this->mailbox->connection()
            ->folderStatus($this->path)
            ->getValidatedData();
    }

    /**
     * Examine the current folder.
     */
    public function examine(): array
    {
        return $this->mailbox->connection()
            ->examineFolder($this->path)
            ->getValidatedData();
    }

    /**
     * Expunge the mailbox.
     */
    public function expunge(): array
    {
        return $this->mailbox->connection()
            ->expunge()
            ->getValidatedData();
    }

    /**
     * Determine if the current connection has IDLE support.
     */
    protected function hasIdleSupport(): bool
    {
        return in_array('IDLE', $this->capabilities());
    }

    /**
     * Get the connection's capabilities.
     */
    protected function capabilities(): array
    {
        return $this->capabilities ??= $this->mailbox->connection()
            ->capability()
            ->getValidatedData();
    }
}
