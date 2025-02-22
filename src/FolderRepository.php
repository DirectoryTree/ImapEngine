<?php

namespace DirectoryTree\ImapEngine;

use DirectoryTree\ImapEngine\Collections\FolderCollection;
use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;

class FolderRepository implements FolderRepositoryInterface
{
    /**
     * Constructor.
     */
    public function __construct(
        protected Mailbox $mailbox
    ) {}

    /**
     * {@inheritDoc}
     */
    public function find(string $folder): ?FolderInterface
    {
        return $this->get($folder)->first();
    }

    /**
     * {@inheritDoc}
     */
    public function findOrFail(string $folder): FolderInterface
    {
        return $this->get($folder)->firstOrFail();
    }

    /**
     * {@inheritDoc}
     */
    public function create(string $folder): FolderInterface
    {
        $this->mailbox->connection()->create($folder);

        return $this->find($folder);
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
        return $this->mailbox->connection()->list($reference, $match)->map(
            fn (UntaggedResponse $response) => new Folder(
                mailbox: $this->mailbox,
                path: $response->tokenAt(4)->value,
                flags: $response->tokenAt(2)->values(),
                delimiter: $response->tokenAt(3)->value,
            )
        )->pipeInto(FolderCollection::class);
    }
}
