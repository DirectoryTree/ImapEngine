<?php

namespace DirectoryTree\ImapEngine;

use DirectoryTree\ImapEngine\Connection\Responses\Data\ListData;
use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
use DirectoryTree\ImapEngine\Exceptions\RuntimeException;

class FetchedMessageData
{
    /**
     * Constructor.
     */
    public function __construct(
        protected int $uid,
        protected array $flags = [],
        protected string $head = '',
        protected string $body = '',
        protected ?int $size = null,
        protected ?ListData $bodyStructure = null,
    ) {}

    /**
     * Create message data from an IMAP FETCH response.
     */
    public static function fromResponse(UntaggedResponse $response): static
    {
        $data = $response->tokenAt(3);

        if (! $data instanceof ListData) {
            throw new RuntimeException(sprintf(
                'Expected instance of %s at index 3 in FETCH response, got %s',
                ListData::class,
                get_debug_type($data)
            ));
        }

        return new static(
            uid: (int) $data->lookup('UID')->value,
            flags: $data->lookup('FLAGS')?->values() ?? [],
            head: $data->lookup('[HEADER]')->value ?? '',
            body: $data->lookup('[TEXT]')->value ?? '',
            size: ($size = $data->lookup('RFC822.SIZE')?->value) ? (int) $size : null,
            bodyStructure: ($bodyStructure = $data->lookup('BODYSTRUCTURE')) instanceof ListData
                ? $bodyStructure
                : null,
        );
    }

    /**
     * Get the message UID.
     */
    public function uid(): int
    {
        return $this->uid;
    }

    /**
     * Create a message for the given folder.
     */
    public function toMessage(FolderInterface $folder): Message
    {
        return new Message(
            $folder,
            $this->uid,
            $this->flags,
            $this->head,
            $this->body,
            $this->size,
            $this->bodyStructure,
        );
    }
}
