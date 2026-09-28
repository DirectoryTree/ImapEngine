<?php

namespace DirectoryTree\ImapEngine\Idle\Events;

use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
use DirectoryTree\ImapEngine\Connection\Tokens\Number;

abstract class ResponseEvent implements EventInterface
{
    /**
     * Constructor.
     */
    public function __construct(
        protected string $folder,
        protected UntaggedResponse $response,
    ) {}

    /**
     * Get the folder path.
     */
    public function folder(): string
    {
        return $this->folder;
    }

    /**
     * Get the protocol response type.
     */
    public function type(): string
    {
        return strtoupper(
            $this->response->type() instanceof Number
                ? $this->response->tokenAt(2)->value
                : $this->response->type()->value
        );
    }

    /**
     * Get the original server response.
     */
    public function response(): UntaggedResponse
    {
        return $this->response;
    }
}
