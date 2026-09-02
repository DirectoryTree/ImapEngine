<?php

namespace DirectoryTree\ImapEngine;

use DirectoryTree\ImapEngine\Collections\FolderCollection;
use DirectoryTree\ImapEngine\Enums\ImapSpecialUse;

trait ResolvesSpecialUseFolders
{
    /**
     * Find a folder by its special use.
     */
    public function findBySpecialUse(ImapSpecialUse $specialUse): ?FolderInterface
    {
        return $this->foldersForSpecialUse()->findBySpecialUse($specialUse);
    }

    /**
     * Get the folder containing all messages.
     */
    public function allMail(): ?FolderInterface
    {
        return $this->findBySpecialUse(ImapSpecialUse::All);
    }

    /**
     * Get the archive folder.
     */
    public function archive(): ?FolderInterface
    {
        return $this->findBySpecialUse(ImapSpecialUse::Archive);
    }

    /**
     * Get the drafts folder.
     */
    public function drafts(): ?FolderInterface
    {
        return $this->findBySpecialUse(ImapSpecialUse::Drafts);
    }

    /**
     * Get the flagged folder.
     */
    public function flagged(): ?FolderInterface
    {
        return $this->findBySpecialUse(ImapSpecialUse::Flagged);
    }

    /**
     * Get the junk folder.
     */
    public function junk(): ?FolderInterface
    {
        return $this->findBySpecialUse(ImapSpecialUse::Junk);
    }

    /**
     * Get the sent folder.
     */
    public function sent(): ?FolderInterface
    {
        return $this->findBySpecialUse(ImapSpecialUse::Sent);
    }

    /**
     * Get the trash folder.
     */
    public function trash(): ?FolderInterface
    {
        return $this->findBySpecialUse(ImapSpecialUse::Trash);
    }

    /**
     * Get the folders used to resolve special uses.
     */
    abstract protected function foldersForSpecialUse(): FolderCollection;
}
