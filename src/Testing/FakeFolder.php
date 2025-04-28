<?php

namespace DirectoryTree\ImapEngine\Testing;

use DirectoryTree\ImapEngine\Exceptions\Exception;
use DirectoryTree\ImapEngine\FolderInterface;
use DirectoryTree\ImapEngine\MailboxInterface;
use DirectoryTree\ImapEngine\MessageQueryInterface;
use DirectoryTree\ImapEngine\Support\Str;

class FakeFolder implements FolderInterface
{
    /**
     * Constructor.
     */
    public function __construct(
        protected string $path = '',
        protected array $flags = [],
        /** @var FakeMessage[] */
        protected array $messages = [],
        protected string $delimiter = '/',
        protected ?MailboxInterface $mailbox = null,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function mailbox(): MailboxInterface
    {
        return $this->mailbox ?? throw new Exception('Folder has no mailbox.');
    }

    /**
     * {@inheritDoc}
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * {@inheritDoc}
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
        return Str::decodeUtf7Imap(
            last(explode($this->delimiter, $this->path))
        );
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
    public function messages(): MessageQueryInterface
    {
        // Ensure the folder is selected.
        $this->select(true);

        return new FakeMessageQuery($this, $this->messages);
    }

    /**
     * {@inheritDoc}
     */
    public function idle(callable $callback, ?callable $query = null, int $timeout = 300): void
    {
        throw new Exception('Unsupported');
    }

    /**
     * {@inheritDoc}
     */
    public function move(string $newPath): void
    {
        // Do nothing.
    }

    /**
     * {@inheritDoc}
     */
    public function select(bool $force = false): void
    {
        $this->mailbox?->select($this, $force);
    }

    /**
     * {@inheritDoc}
     */
    public function status(): array
    {
        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function examine(): array
    {
        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function expunge(): array
    {
        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function delete(): void
    {
        // Do nothing.
    }

    /**
     * Set the folder's path.
     */
    public function setPath(string $path): FakeFolder
    {
        $this->path = $path;

        return $this;
    }

    /**
     * Set the folder's flags.
     */
    public function setFlags(array $flags): FakeFolder
    {
        $this->flags = $flags;

        return $this;
    }

    /**
     * Set the folder's mailbox.
     */
    public function setMailbox(MailboxInterface $mailbox): FakeFolder
    {
        $this->mailbox = $mailbox;

        return $this;
    }

    /**
     * Set the folder's messages.
     */
    public function setMessages(array $messages): FakeFolder
    {
        $this->messages = $messages;

        return $this;
    }

    /**
     * Set the folder's delimiter.
     */
    public function setDelimiter(string $delimiter = '/'): FakeFolder
    {
        $this->delimiter = $delimiter;

        return $this;
    }
}
