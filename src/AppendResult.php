<?php

namespace DirectoryTree\ImapEngine;

use DirectoryTree\ImapEngine\Connection\Responses\Data\ResponseCodeData;
use DirectoryTree\ImapEngine\Connection\Responses\TaggedResponse;
use DirectoryTree\ImapEngine\Connection\Tokens\Token;

class AppendResult
{
    /**
     * Constructor.
     */
    public function __construct(
        protected ?int $uidValidity = null,
        protected ?int $uid = null,
    ) {}

    /**
     * Create an append result from the tagged response.
     */
    public static function fromResponse(TaggedResponse $response): static
    {
        $data = $response->tokenAt(2);

        if (! $data instanceof ResponseCodeData) {
            return new static;
        }

        $code = $data->first();

        if (! $code instanceof Token || ! $code->is('APPENDUID')) {
            return new static;
        }

        return new static(
            uidValidity: (int) $data->tokenAt(1)->value,
            uid: (int) $data->tokenAt(2)->value,
        );
    }

    /**
     * Get the mailbox UID validity value.
     */
    public function uidValidity(): ?int
    {
        return $this->uidValidity;
    }

    /**
     * Get the appended message UID.
     */
    public function uid(): ?int
    {
        return $this->uid;
    }
}
