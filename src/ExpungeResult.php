<?php

namespace DirectoryTree\ImapEngine;

use DirectoryTree\ImapEngine\Collections\ResponseCollection;
use DirectoryTree\ImapEngine\Collections\VanishedCollection;
use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
use DirectoryTree\ImapEngine\Connection\Tokens\Number;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

class ExpungeResult
{
    /**
     * Constructor.
     *
     * @param  Collection<int, int>  $sequenceNumbers
     */
    public function __construct(
        protected Collection $sequenceNumbers = new Collection,
        protected VanishedCollection $vanished = new VanishedCollection,
        protected ResponseCollection $responses = new ResponseCollection,
    ) {}

    /**
     * Collect removal identifiers without mixing sequence numbers and UIDs.
     */
    public static function fromResponses(ResponseCollection $responses): static
    {
        $numbers = $responses->untagged()
            ->filter(fn (UntaggedResponse $response) => $response->type() instanceof Number
                && $response->tokenAt(2)?->is('EXPUNGE'))
            ->map(fn (UntaggedResponse $response) => (int) $response->type()->value)
            ->values();

        return new static($numbers->toBase(), Vanished::collect($responses), $responses);
    }

    /**
     * Get expunged sequence numbers in server order, including repeated positions.
     *
     * @return Collection<int, int>
     */
    public function sequenceNumbers(): Collection
    {
        return $this->sequenceNumbers;
    }

    /**
     * Get the vanished UID groups reported by QRESYNC.
     */
    public function vanished(): VanishedCollection
    {
        return $this->vanished;
    }

    /**
     * Get unique vanished UIDs without mixing them with sequence numbers.
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
     * Get the raw server responses, including unsolicited updates.
     */
    public function responses(): ResponseCollection
    {
        return $this->responses;
    }
}
