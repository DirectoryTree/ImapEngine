<?php

namespace DirectoryTree\ImapEngine;

use DirectoryTree\ImapEngine\Collections\FolderCollection;
use DirectoryTree\ImapEngine\Enums\ImapSpecialUse;

interface FolderRepositoryInterface
{
    /**
     * Find a folder.
     */
    public function find(string $path): ?FolderInterface;

    /**
     * Find a folder or throw an exception.
     */
    public function findOrFail(string $path): FolderInterface;

    /**
     * Create a new folder.
     */
    public function create(string $path): FolderInterface;

    /**
     * Find or create a folder.
     */
    public function firstOrCreate(string $path): FolderInterface;

    /**
     * Find a folder by its special use.
     */
    public function findBySpecialUse(ImapSpecialUse $specialUse): ?FolderInterface;

    /**
     * Get the folder containing all messages.
     */
    public function allMail(): ?FolderInterface;

    /**
     * Get the archive folder.
     */
    public function archive(): ?FolderInterface;

    /**
     * Get the drafts folder.
     */
    public function drafts(): ?FolderInterface;

    /**
     * Get the flagged folder.
     */
    public function flagged(): ?FolderInterface;

    /**
     * Get the junk folder.
     */
    public function junk(): ?FolderInterface;

    /**
     * Get the sent folder.
     */
    public function sent(): ?FolderInterface;

    /**
     * Get the trash folder.
     */
    public function trash(): ?FolderInterface;

    /**
     * Get the mailboxes folders.
     */
    public function get(?string $match = '*', ?string $reference = '', array $return = []): FolderCollection;
}
