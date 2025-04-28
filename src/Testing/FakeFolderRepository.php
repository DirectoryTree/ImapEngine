<?php

namespace DirectoryTree\ImapEngine\Testing;

use DirectoryTree\ImapEngine\Collections\FolderCollection;
use DirectoryTree\ImapEngine\FolderInterface;
use DirectoryTree\ImapEngine\FolderRepositoryInterface;
use DirectoryTree\ImapEngine\MailboxInterface;
use DirectoryTree\ImapEngine\Support\Str;

class FakeFolderRepository implements FolderRepositoryInterface
{
    /**
     * Constructor.
     */
    public function __construct(
        protected MailboxInterface $mailbox,
        /** @var FolderInterface[] */
        protected array $folders = []
    ) {}

    /**
     * {@inheritDoc}
     */
    public function find(string $path): ?FolderInterface
    {
        return $this->get()->first(
            fn (FolderInterface $folder) => $folder->path() === $path
        );
    }

    /**
     * {@inheritDoc}
     */
    public function findOrFail(string $path): FolderInterface
    {
        return $this->get()->firstOrFail(
            fn (FolderInterface $folder) => $folder->path() === $path
        );
    }

    /**
     * {@inheritDoc}
     */
    public function create(string $path): FolderInterface
    {
        return $this->folders[] = new FakeFolder($path, mailbox: $this->mailbox);
    }

    /**
     * {@inheritDoc}
     */
    public function firstOrCreate(string $path): FolderInterface
    {
        return $this->find($path) ?? $this->create($path);
    }

    /**
     * {@inheritDoc}
     */
    public function get(?string $match = '*', ?string $reference = ''): FolderCollection
    {
        return FolderCollection::make($this->folders)->filter(
            fn (FolderInterface $folder) => Str::is($match, $folder->path())
        );
    }
}
