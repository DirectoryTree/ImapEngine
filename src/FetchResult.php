<?php

namespace DirectoryTree\ImapEngine;

use DirectoryTree\ImapEngine\Collections\FetchedResponseCollection;
use DirectoryTree\ImapEngine\Collections\ResponseCollection;
use DirectoryTree\ImapEngine\Collections\VanishedCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

class FetchResult
{
    /**
     * Constructor.
     *
     * @param  Collection<int, FetchedMessageData>  $messages
     */
    public function __construct(
        protected Collection $messages = new Collection,
        protected VanishedCollection $vanished = new VanishedCollection,
        protected ResponseCollection $responses = new ResponseCollection,
    ) {}

    /**
     * Create a fetch result from IMAP responses and selected fetched responses.
     */
    public static function fromResponses(
        ResponseCollection $responses,
        ?FetchedResponseCollection $fetches = null,
        ?VanishedCollection $vanished = null,
    ): static {
        $fetches ??= FetchedResponse::collect($responses);
        $vanished ??= Vanished::collect($responses);

        return new static(
            $fetches->messages(),
            $vanished,
            $responses,
        );
    }

    /**
     * Get the fetched messages.
     *
     * @return Collection<int, FetchedMessageData>
     */
    public function messages(): Collection
    {
        return $this->messages;
    }

    /**
     * Get the vanished message groups.
     */
    public function vanished(): VanishedCollection
    {
        return $this->vanished;
    }

    /**
     * Get all unique vanished message UIDs.
     *
     * @return LazyCollection<int, int>
     */
    public function vanishedUids(): LazyCollection
    {
        return $this->vanished->lazy()
            ->flatMap(fn (Vanished $vanished) => $vanished->uids())
            ->unique()
            ->values();
    }

    /**
     * Get the raw IMAP responses.
     */
    public function responses(): ResponseCollection
    {
        return $this->responses;
    }
}
