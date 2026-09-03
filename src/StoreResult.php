<?php

namespace DirectoryTree\ImapEngine;

use DirectoryTree\ImapEngine\Collections\ResponseCollection;
use DirectoryTree\ImapEngine\Connection\Responses\Data\ResponseCodeData;
use DirectoryTree\ImapEngine\Connection\Responses\TaggedResponse;
use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
use DirectoryTree\ImapEngine\Support\Str;

class StoreResult
{
    /**
     * Constructor.
     */
    public function __construct(
        protected TaggedResponse $response,
        protected array $messages = [],
        protected array $modified = [],
        protected ?ResponseCollection $responses = null,
    ) {}

    /**
     * Create a store result from IMAP responses.
     *
     * @param  (callable(FetchedMessageData, UntaggedResponse): bool)|null  $filter
     */
    public static function fromResponses(ResponseCollection $responses, TaggedResponse $response, ?callable $filter = null): static
    {
        $messages = FetchResult::fromResponses($responses, $filter)->messages();

        $code = $response->tokenAt(2);
        $modified = $code instanceof ResponseCodeData && strtoupper($code->first()?->value ?? '') === 'MODIFIED'
            ? Str::fromSequenceSet($code->tokenAt(1)->value)
            : [];

        return new static($response, $messages, $modified, $responses);
    }

    public function response(): TaggedResponse
    {
        return $this->response;
    }

    /**
     * Get the messages whose flags were changed.
     *
     * @return FetchedMessageData[]
     */
    public function messages(): array
    {
        return $this->messages;
    }

    /**
     * Get the UIDs or message numbers rejected because they changed after the checkpoint.
     */
    public function modified(): array
    {
        return $this->modified;
    }

    public function successful(): bool
    {
        return $this->response->successful();
    }

    public function responses(): ResponseCollection
    {
        return $this->responses ?? new ResponseCollection;
    }
}
