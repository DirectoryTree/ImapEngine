<?php

namespace DirectoryTree\ImapEngine;

use DirectoryTree\ImapEngine\Collections\ResponseCollection;
use DirectoryTree\ImapEngine\Collections\VanishedCollection;
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
        $earlier = $data instanceof ListData
            && strtoupper((string) ($data->values()[0] ?? '')) === 'EARLIER';
        $sequenceSet = $response->tokenAt($earlier ? 3 : 2);

        return new static(
            Str::fromSequenceSet($sequenceSet->value),
            $earlier,
        );
    }

    /**
     * Parse vanished responses from a collection of raw IMAP responses.
     */
    public static function collect(ResponseCollection $responses): VanishedCollection
    {
        return new VanishedCollection(
            $responses->vanished()
                ->map(fn (UntaggedResponse $response) => static::fromResponse($response))
                ->values()
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
