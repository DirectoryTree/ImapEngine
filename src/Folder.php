<?php

namespace DirectoryTree\ImapEngine;

use DirectoryTree\ImapEngine\Connection\ImapFetchIdentifier;
use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
use DirectoryTree\ImapEngine\Exceptions\Exception;
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
        if (! $this->hasCapability('IDLE')) {
            throw new RuntimeException('IMAP server does not support IDLE');
        }

        $fetch = function (int $msgn) {
            return $this->messages()
                ->withHeaders()
                ->withFlags()
                ->withBody()
                ->find($msgn, ImapFetchIdentifier::MessageNumber);
        };

        (new Idle(clone $this->mailbox, $this->path, $timeout))->await(
            function (int $msgn) use ($callback, $fetch) {
                // Connect the client if the connection is closed.
                if ($this->mailbox->isClosed()) {
                    $this->mailbox->connect();
                }

                try {
                    $message = $fetch($msgn);
                } catch (Exception) {
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
    public function move(string $newPath, bool $expunge = true): void
    {
        $this->mailbox->connection()->rename($this->path, $newPath);

        if ($expunge) {
            $this->expunge();
        }

        $this->path = $newPath;
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
     * Examine the current folder and get detailed status information.
     */
    public function examine(): array
    {
        return $this->mailbox->connection()->examine($this->path)->map(
            fn (UntaggedResponse $response) => $response->toArray()
        )->all();
    }

    /**
     * Expunge the mailbox and return the expunged message sequence numbers.
     */
    public function expunge(): array
    {
        return $this->mailbox->connection()->expunge()->map(
            fn (UntaggedResponse $response) => $response->tokenAt(1)->value
        )->all();
    }

    /**
     * Delete the current folder.
     */
    public function delete(bool $expunge = true): void
    {
        $this->mailbox->connection()->delete($this->path);

        if ($expunge) {
            $this->expunge();
        }
    }

    /**
     * Determine if the mailbox has the given capability.
     */
    public function hasCapability(string $capability): bool
    {
        return in_array($capability, $this->capabilities());
    }

    /**
     * Get and in-memory cache the mailboxes's capabilities.
     */
    protected function capabilities(): array
    {
        return $this->capabilities ??= $this->mailbox->capabilities();
    }
}
