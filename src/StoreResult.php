<?php

namespace DirectoryTree\ImapEngine;

use DirectoryTree\ImapEngine\Collections\FetchedResponseCollection;
use DirectoryTree\ImapEngine\Collections\ResponseCollection;
use DirectoryTree\ImapEngine\Connection\Responses\Data\ResponseCodeData;
use DirectoryTree\ImapEngine\Connection\Responses\TaggedResponse;
use DirectoryTree\ImapEngine\Support\SequenceSet;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

class StoreResult
{
    /**
     * Constructor.
     *
     * @param  Collection<int, FetchedMessageData>  $messages
     * @param  LazyCollection<int, int>  $modified
     */
    public function __construct(
        protected TaggedResponse $response,
        protected Collection $messages = new Collection,
        protected LazyCollection $modified = new LazyCollection,
        protected ResponseCollection $responses = new ResponseCollection,
    ) {}

    /**
     * Create a store result from IMAP responses and selected fetched responses.
     */
    public static function fromResponses(ResponseCollection $responses, TaggedResponse $response, FetchedResponseCollection $fetches): static
    {
        $code = $response->tokenAt(2);

        $modified = $code instanceof ResponseCodeData && strtoupper($code->first()?->value ?? '') === 'MODIFIED'
            ? SequenceSet::parse($code->tokenAt(1)->value)
            : new LazyCollection;

        return new static($response, $fetches->messages(), $modified, $responses);
    }

    /**
     * Get the tagged response that completed the STORE command.
     */
    public function response(): TaggedResponse
    {
        return $this->response;
    }

    /**
     * Get the messages whose flags were changed.
     *
     * @return Collection<int, FetchedMessageData>
     */
    public function messages(): Collection
    {
        return $this->messages;
    }

    /**
     * Get the UIDs or message numbers rejected because they changed after the checkpoint.
     *
     * @return LazyCollection<int, int>
     */
    public function modified(): LazyCollection
    {
        return $this->modified;
    }

    /**
     * Get the raw IMAP responses.
     */
    public function responses(): ResponseCollection
    {
        return $this->responses;
    }
}
