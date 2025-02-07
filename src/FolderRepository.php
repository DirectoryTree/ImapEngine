<?php

namespace DirectoryTree\ImapEngine;

use DirectoryTree\ImapEngine\Collections\FolderCollection;
use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;

class FolderRepository
{
    /**
     * Constructor.
     */
    public function __construct(
        protected Mailbox $mailbox
    ) {}

    /**
     * Find a folder.
     */
    public function find(string $nameOrPath, ?string $delimiter = null): ?Folder
    {
        $delimiter ?? $this->mailbox->config('delimiter', '/');

        if (str_contains($nameOrPath, $delimiter)) {
            return $this->findByPath($nameOrPath);
        }

        return $this->findByName($nameOrPath);
    }

    /**
     * Get a folder instance by a folder name.
     */
    public function findByName(string $name): ?Folder
    {
        return $this->get()->filter(
            fn (Folder $folder) => $folder->name() === $name
        )->first();
    }

    /**
     * Find a folder by the given path.
     */
    public function findByPath(string $path): Folder
    {
        return $this->get()->filter(
            fn (Folder $folder) => $folder->path() === $path
        )->first();
    }

    /**
     * Get the mailboxes folders.
     */
    public function get(?string $parentFolder = null): FolderCollection
    {
        return $this->mailbox->connection()->folders('', $parentFolder.'*')->map(
            fn (UntaggedResponse $response) => new Folder(
                mailbox: $this->mailbox,
                path: $response->tokenAt(4)->value,
                flags: $response->tokenAt(2)->values(),
                delimiter: $response->tokenAt(3)->value,
            )
        )->pipeInto(FolderCollection::class);
    }

    /**
     * Create a new folder.
     */
    public function create(string $folderPath, bool $expunge = true): Folder
    {
        $response = $this->mailbox->connection()->createFolder($folderPath);

        if ($expunge) {
            $this->expunge();
        }

        return $this->findByPath($folderPath);
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
}
