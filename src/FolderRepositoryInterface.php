<?php

namespace DirectoryTree\ImapEngine;

use DirectoryTree\ImapEngine\Collections\FolderCollection;

interface FolderRepositoryInterface
{
    /**
     * Find a folder.
     */
    public function find(string $folder): ?FolderInterface;

    /**
     * Find a folder or throw an exception.
     */
    public function findOrFail(string $folder): FolderInterface;

    /**
     * Create a new folder.
     */
    public function create(string $folder): FolderInterface;

    /**
     * Find or create a folder.
     */
    public function firstOrCreate(string $folder): FolderInterface;

    /**
     * Get the mailboxes folders.
     */
    public function get(?string $match = '*', ?string $reference = ''): FolderCollection;
}
