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
     * Get a folder instance by a folder name.
     */
    public function find(string $folderName, ?string $delimiter = null): ?Folder
    {
        $delimiter ?? $this->mailbox->config('delimiter', '/');

        if (str_contains($folderName, $delimiter)) {
            return $this->findByPath($folderName);
        }

        return $this->findByName($folderName);
    }

    /**
     * Get a folder instance by a folder name.
     *
     * @param  bool  $softFail  If true, it will return null instead of throwing an exception
     */
    public function findByName(string $folderName, bool $softFail = false): ?Folder
    {
        return $this->get(false)->where('name', $folderName)->first();
    }

    /**
     * Find a folder by the given path.
     */
    public function findByPath(string $folderPath): Folder
    {
        return $this->get()->where('path', $folderPath)->first();
    }

    /**
     * Get the mailboxes folders.
     */
    public function get(?string $parentFolder = null): FolderCollection
    {
        $folders = FolderCollection::make();

        $items = $this->mailbox->connection()->folders('', $parentFolder.'*')->getValidatedData();

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
     * Get folders list.
     *
     * If hierarchical order is set to true, it will make a tree of folders, otherwise it will return flat array.
     *
     * @param  bool  $softFail  If true, it will return an empty collection instead of throwing an exception
     */
    public function getFoldersWithStatus(bool $hierarchical = true, ?string $parentFolder = null, bool $softFail = false): FolderCollection
    {
        $this->checkConnection();

        $folders = FolderCollection::make();

        $pattern = $parentFolder.($hierarchical ? '%' : '*');

        $items = $this->connection->folders('', $pattern)->getValidatedData();

        if (! empty($items)) {
            foreach ($items as $folderName => $item) {
                $folder = new Folder($this, $folderName, $item['delimiter'], $item['flags']);

                if ($hierarchical && $folder->hasChildren()) {
                    $pattern = $folder->fullName.$folder->delimiter.'%';

                    $children = $this->getFoldersWithStatus(true, $pattern, true);

                    $folder->setChildren($children);
                }

                $folder->loadStatus();

                $folders->push($folder);
            }

            return $folders;
        } elseif (! $softFail) {
            throw new FolderFetchingException('Failed to fetch any folders');
        }

        return $folders;
    }

    /**
     * Open a given folder.
     */
    public function openFolder(string $folderPath, bool $forceSelect = false): array
    {
        if ($this->activeFolder == $folderPath && $this->isConnected() && ! $forceSelect) {
            return [];
        }

        $this->checkConnection();

        $this->activeFolder = $folderPath;

        return $this->connection->selectFolder($folderPath)->getValidatedData();
    }

    /**
     * Set active folder.
     */
    public function setActiveFolder(?string $folderPath = null): void
    {
        $this->activeFolder = $folderPath;
    }

    /**
     * Get active folder.
     */
    public function getActiveFolder(): ?string
    {
        return $this->activeFolder;
    }

    /**
     * Create a new Folder.
     */
    public function createFolder(string $folderPath, bool $expunge = true, bool $utf7 = false): Folder
    {
        $this->checkConnection();

        if (! $utf7) {
            $folderPath = EncodingAliases::convert($folderPath, 'utf-8', 'UTF7-IMAP');
        }

        $status = $this->connection->createFolder($folderPath)->getValidatedData();

        if ($expunge) {
            $this->expunge();
        }

        $folder = $this->findByPath($folderPath, true);

        if ($status && $folder) {
            $this->dispatch('folder', 'new', $folder);
        }

        return $folder;
    }

    /**
     * Delete a given folder.
     */
    public function deleteFolder(string $folderPath, bool $expunge = true): array
    {
        $this->checkConnection();

        $folder = $this->findByPath($folderPath);

        if ($this->activeFolder == $folder->path) {
            $this->activeFolder = null;
        }

        $status = $this->getConnection()->deleteFolder($folder->path)->getValidatedData();

        if ($expunge) {
            $this->expunge();
        }

        $this->dispatch('folder', 'deleted', $folder);

        return $status;
    }

    /**
     * Check a given folder.
     */
    public function checkFolder(string $folderPath): array
    {
        $this->checkConnection();

        return $this->connection->examineFolder($folderPath)->getValidatedData();
    }

    /**
     * Get the current active folder.
     */
    public function getFolderPath(): ?string
    {
        return $this->activeFolder;
    }


    /**
     * Retrieve the quota level settings, and usage statics per mailbox.
     */
    public function getQuota(): array
    {
        //$this->checkConnection();

        return $this->connection->getQuota($this->config('username'))->getValidatedData();
    }

    /**
     * Retrieve the quota settings per user.
     */
    public function getQuotaRoot(string $quotaRoot = 'INBOX'): array
    {
        $this->checkConnection();

        return $this->connection->getQuotaRoot($quotaRoot)->getValidatedData();
    }
}