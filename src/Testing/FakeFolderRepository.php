<?php

namespace DirectoryTree\ImapEngine\Testing;

use DirectoryTree\ImapEngine\Collections\FolderCollection;
use DirectoryTree\ImapEngine\FolderInterface;
use DirectoryTree\ImapEngine\FolderRepositoryInterface;
use DirectoryTree\ImapEngine\MailboxInterface;
use Illuminate\Support\ItemNotFoundException;

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
    public function find(string $folder): ?FolderInterface
    {
        return $this->folders[$folder] ?? null;
    }

    /**
     * {@inheritDoc}
     */
    public function findOrFail(string $folder): FolderInterface
    {
        return $this->folders[$folder] ?? throw new ItemNotFoundException("Folder [{$folder}] not found.");
    }

    /**
     * {@inheritDoc}
     */
    public function create(string $folder): FolderInterface
    {
        return $this->folders[$folder] = new FakeFolder($folder, mailbox: $this->mailbox);
    }

    /**
     * {@inheritDoc}
     */
    public function firstOrCreate(string $folder): FolderInterface
    {
        return $this->find($folder) ?? $this->create($folder);
    }

    /**
     * {@inheritDoc}
     */
    public function get(?string $match = '*', ?string $reference = ''): FolderCollection
    {
        $pattern = str_replace(
            ['*', '%'],
            ['.*', '[^/]*'],
            preg_quote($match, '/'),
        );

        return FolderCollection::make($this->folders)->filter(
            fn (FolderInterface $folder) => (bool) preg_match('/^'.$pattern.'$/', $folder->path())
        );
    }
}
