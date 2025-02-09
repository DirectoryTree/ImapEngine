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
    public function find(string $folder): ?Folder
    {
        return $this->get($folder)->first();
    }

    /**
     * Create a new folder.
     */
    public function create(string $folder, bool $expunge = true): Folder
    {
        $this->mailbox->connection()->create($folder);

        if ($expunge) {
            $this->mailbox->connection()->expunge();
        }

        return $this->find($folder);
    }

    /**
     * Get the mailboxes folders.
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
