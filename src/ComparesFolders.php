<?php

namespace DirectoryTree\ImapEngine;

trait ComparesFolders
{
    /**
     * Determine if two folders are the same.
     */
    protected function isSameFolder(FolderInterface $a, FolderInterface $b): bool
    {
        return $a->path() === $b->path()
            && $a->mailbox()->fingerprint() === $b->mailbox()->fingerprint();
    }
}
