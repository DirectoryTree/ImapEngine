<?php

namespace DirectoryTree\ImapEngine;

class Folder
{
    /**
     * Constructor.
     */
    public function __construct(
        protected Mailbox $mailbox,
        protected string $path,
        protected string $delimiter = '/',
        protected array $flags = [],
    ){}

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
     * Get the folder delimiter.
     */
    public function delimiter(): string
    {
        return $this->delimiter;
    }

    /**
     * Get the folder flags.
     */
    public function flags(): array
    {
        return $this->flags;
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
            && $this->mailbox->config('host') === $folder->mailbox->config('host');
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
     * Select the current folder.
     */
    public function select(): void
    {
        $this->mailbox->select($this);
    }

    /**
     * Delete the current folder.
     */
    public function delete(bool $expunge = true): array
    {
        $this->select();

        $status = $this->mailbox->connection()
            ->deleteFolder($this->path)
            ->getValidatedData();

        if ($expunge) {
            $this->expunge();
        }

        return $status;
    }

    /**
     * Execute an EXPUNGE command.
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
            ->getCapabilities()
            ->getValidatedData();
    }
}