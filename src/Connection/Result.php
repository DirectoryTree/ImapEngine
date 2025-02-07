<?php

namespace DirectoryTree\ImapEngine\Connection;

use DirectoryTree\ImapEngine\Collections\ResponseCollection;
use DirectoryTree\ImapEngine\Connection\Responses\Response;

class Result
{
    /**
     * Constructor.
     */
    public function __construct(
        protected int $sequence = 0,
        protected array $commands = [],
        protected array $responses = [],
    ) {
        $this->sequence = $sequence > 0 ? $sequence : $this->getUniqueSequence();
    }

    /**
     * Get a unique sequence number.
     */
    protected function getUniqueSequence(): int
    {
        return (int) str_replace('.', '', (string) microtime(true));
    }

    public function addCommand(ImapCommand $command): void
    {
        $this->commands[] = $command;
    }

    public function addResponse(Response $response): void
    {
        $this->responses[] = $response;
    }

    public function responses(): ResponseCollection
    {
        return new ResponseCollection($this->responses);
    }

    /**
     * Get the response sequence.
     */
    public function sequence(): int
    {
        return $this->sequence;
    }
}
