<?php

namespace DirectoryTree\ImapEngine;

use DirectoryTree\ImapEngine\Collections\ResponseCollection;
use DirectoryTree\ImapEngine\Collections\VanishedCollection;
use DirectoryTree\ImapEngine\Connection\Responses\Data\ListData;
use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
use DirectoryTree\ImapEngine\Support\MessageSetMatcher;
use DirectoryTree\ImapEngine\Support\SequenceSet;
use Illuminate\Support\LazyCollection;

class Vanished
{
    /**
     * The compact server sequence set, retained for filtering before expansion.
     */
    protected ?string $sequenceSet = null;

    /**
     * Constructor.
     *
     * @param  LazyCollection<int, int>  $uids
     */
    public function __construct(
        protected LazyCollection $uids,
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

        $vanished = new static(SequenceSet::parse($sequenceSet->value), $earlier);
        $vanished->sequenceSet = $sequenceSet->value;

        return $vanished;
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
     * Filter UIDs, intersecting compact server ranges before expanding them.
     */
    public function forMessageSet(MessageSetMatcher $matcher): static
    {
        if ($this->sequenceSet === null) {
            return new static($this->uids->filter($matcher->contains(...))->values(), $this->earlier);
        }

        $set = $matcher->intersect($this->sequenceSet);
        $vanished = new static($set === '' ? new LazyCollection : SequenceSet::parse($set), $this->earlier);
        $vanished->sequenceSet = $set;

        return $vanished;
    }

    /**
     * Get the vanished message UIDs.
     *
     * @return LazyCollection<int, int>
     */
    public function uids(): LazyCollection
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
