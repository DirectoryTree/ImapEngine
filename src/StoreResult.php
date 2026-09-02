<?php

namespace DirectoryTree\ImapEngine;

use DirectoryTree\ImapEngine\Collections\ResponseCollection;
use DirectoryTree\ImapEngine\Connection\Responses\Data\ResponseCodeData;
use DirectoryTree\ImapEngine\Connection\Responses\TaggedResponse;
use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
use DirectoryTree\ImapEngine\Connection\Tokens\Token;
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
     */
    public static function fromResponses(ResponseCollection $responses, TaggedResponse $response): static
    {
        $messages = $responses->untagged()
            ->filter(fn (UntaggedResponse $response) => ($type = $response->tokenAt(2)) instanceof Token && $type->is('FETCH'))
            ->map(fn (UntaggedResponse $response) => FetchedMessageData::fromResponse($response))
            ->values()
            ->all();

        $code = $response->tokenAt(2);
        $modified = $code instanceof ResponseCodeData && strtoupper($code->first()?->value ?? '') === 'MODIFIED'
            ? Str::parseSequenceSet($code->tokenAt(1)->value)
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
     * Get the UIDs rejected because they were modified after the checkpoint.
     */
    public function modified(): array
    {
        return $this->modified;
    }

    /**
     * Get the UIDs rejected because they were modified after the checkpoint.
     */
    public function modifiedUids(): array
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
