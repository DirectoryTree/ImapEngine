<?php

namespace DirectoryTree\ImapEngine;

use DirectoryTree\ImapEngine\Collections\FetchedResponseCollection;
use DirectoryTree\ImapEngine\Collections\ResponseCollection;

class FetchResult
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
     * Create a fetch result from IMAP responses and selected fetched responses.
     */
    public static function fromResponses(ResponseCollection $responses, ?FetchedResponseCollection $fetches = null): static
    {
        $fetches ??= FetchedResponse::collect($responses);

        $vanished = [];

        foreach ($responses->untagged() as $response) {
            if ($response->type()->is('VANISHED')) {
                $vanished[] = Vanished::fromResponse($response);
            }
        }

        return new static(
            $fetches->messages(),
            $vanished,
            $responses,
        );
    }

    /**
     * Get the fetched messages.
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
