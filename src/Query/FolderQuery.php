<?php

namespace DirectoryTree\ImapEngine\Query;

use DirectoryTree\ImapEngine\Collections\FolderCollection;
use DirectoryTree\ImapEngine\Exceptions\RuntimeException;
use DirectoryTree\ImapEngine\Mailbox;
use DirectoryTree\ImapEngine\Folder;

class FolderQuery
{
    /**
     * Constructor.
     */
    public function __construct(
        protected Mailbox $mailbox
    ){}

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
        return $this->get(false)->where('name', $name)->first();
    }

    /**
     * Find a folder by the given path.
     */
    public function findByPath(string $path): Folder
    {
        return $this->get()->where('path', $path)->first();
    }

    /**
     * Get the mailboxes folders.
     */
    public function get(?string $parentFolder = null): FolderCollection
    {
        $folders = FolderCollection::make();

        $items = $this->mailbox->connection()
            ->folders('', $parentFolder.'*')
            ->getValidatedData();

        if (empty($items)) {
            throw new RuntimeException('Failed to fetch any folders');
            //throw new FolderFetchingException('Failed to fetch any folders');
        }

        foreach ($items as $folderName => $item) {
            $folders->push(
                new Folder(
                    $this->mailbox,
                    $folderName,
                    $item['delimiter'],
                    $item['flags'],
                )
            );
        }

        return $folders;
    }

    /**
     * Create a new folder.
     */
    public function create(string $folderPath, bool $expunge = true): Folder
    {
        $this->mailbox->connection()
            ->createFolder($folderPath)
            ->getValidatedData();

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