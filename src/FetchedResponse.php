<?php

namespace DirectoryTree\ImapEngine;

use DirectoryTree\ImapEngine\Collections\ResponseCollection;
use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
use Illuminate\Support\Collection;

class FetchedResponse
{
    /**
     * Constructor.
     */
    public function __construct(
        protected UntaggedResponse $response,
        protected FetchedMessageData $data,
    ) {}

    /**
     * Create a fetched response from an untagged FETCH response.
     */
    public static function fromResponse(UntaggedResponse $response): static
    {
        return new static($response, FetchedMessageData::fromResponse($response));
    }

    /**
     * Parse fetched responses from a collection of raw IMAP responses.
     *
     * @return Collection<int, static>
     */
    public static function collect(ResponseCollection $responses): Collection
    {
        return $responses->fetches()
            ->map(fn (UntaggedResponse $response) => static::fromResponse($response))
            ->values()
            ->toBase();
    }

    /**
     * Get the message sequence number.
     */
    public function sequenceNumber(): int
    {
        return (int) $this->response->type()->value;
    }

    /**
     * Get the fetched message data.
     */
    public function data(): FetchedMessageData
    {
        return $this->data;
    }

    /**
     * Get the original untagged response.
     */
    public function response(): UntaggedResponse
    {
        return $this->response;
    }
}
