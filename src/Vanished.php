<?php

namespace DirectoryTree\ImapEngine;

use DirectoryTree\ImapEngine\Connection\Responses\Data\ListData;
use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
use DirectoryTree\ImapEngine\Support\Str;

class Vanished
{
    /**
     * Constructor.
     */
    public function __construct(
        protected array $uids,
        protected bool $earlier = false,
    ) {}

    /**
     * Create vanished message data from an IMAP VANISHED response.
     */
    public static function fromResponse(UntaggedResponse $response): static
    {
        $data = $response->tokenAt(2);
        $earlier = $data instanceof ListData && $data->contains('EARLIER');
        $sequenceSet = $response->tokenAt($earlier ? 3 : 2);

        return new static(
            Str::fromSequenceSet($sequenceSet->value),
            $earlier,
        );
    }

    /**
     * Get the vanished message UIDs.
     */
    public function uids(): array
    {
        return $this->uids;
    }

    /**
     * Determine if the messages vanished before the requested checkpoint.
     */
    public function earlier(): bool
    {
        return $this->earlier;
    }
}
