<?php

namespace DirectoryTree\ImapEngine;

use DirectoryTree\ImapEngine\Collections\ResponseCollection;
use DirectoryTree\ImapEngine\Connection\Tokens\Token;

class MessageChanges
{
    /**
     * Constructor.
     */
    public function __construct(
        protected array $messages = [],
        protected array $vanished = [],
        protected ?ResponseCollection $responses = null,
    ) {}

    /**
     * Create message changes from IMAP responses.
     */
    public static function fromResponses(ResponseCollection $responses): static
    {
        $messages = [];
        $vanished = [];

        foreach ($responses->untagged() as $response) {
            if ($response->type()->is('VANISHED')) {
                $vanished[] = Vanished::fromResponse($response);
            } elseif (($type = $response->tokenAt(2)) instanceof Token && $type->is('FETCH')) {
                $messages[] = FetchedMessageData::fromResponse($response);
            }
        }

        return new static($messages, $vanished, $responses);
    }

    /**
     * Get the changed messages.
     *
     * @return FetchedMessageData[]
     */
    public function messages(): array
    {
        return $this->messages;
    }

    /**
     * Get the vanished message groups.
     *
     * @return Vanished[]
     */
    public function vanished(): array
    {
        return $this->vanished;
    }

    /**
     * Get all vanished message UIDs.
     */
    public function vanishedUids(): array
    {
        return array_values(array_unique(array_merge(...array_map(
            fn (Vanished $vanished) => $vanished->uids(),
            $this->vanished,
        ))));
    }

    /**
     * Get the raw IMAP responses.
     */
    public function responses(): ResponseCollection
    {
        return $this->responses ?? new ResponseCollection;
    }
}
