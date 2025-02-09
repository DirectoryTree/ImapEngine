<?php

namespace DirectoryTree\ImapEngine\Connection;

use DirectoryTree\ImapEngine\Connection\Responses\ContinuationResponse;
use DirectoryTree\ImapEngine\Connection\Responses\Response;
use DirectoryTree\ImapEngine\Connection\Responses\TaggedResponse;
use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
use DirectoryTree\ImapEngine\Connection\Streams\StreamInterface;
use DirectoryTree\ImapEngine\Exceptions\CommandFailedException;
use DirectoryTree\ImapEngine\Exceptions\RuntimeException;

trait ParsesResponses
{
    /**
     * The result instance.
     */
    protected ?Result $result = null;

    /**
     * The parser instance.
     */
    protected ?ImapParser $parser = null;

    /**
     * Set the current result instance.
     */
    protected function setResult(Result $result): void
    {
        $this->result = $result;
    }

    /**
     * Set the current parser instance.
     */
    protected function setParser(ImapParser $parser): void
    {
        $this->parser = $parser;
    }

    /**
     * Create a new parser instance.
     */
    protected function newParser(StreamInterface $stream): ImapParser
    {
        return new ImapParser(new ImapTokenizer($stream));
    }

    /**
     * Create a new tokenizer instance.
     */
    protected function newTokenizer(StreamInterface $stream): ImapTokenizer
    {
        return new ImapTokenizer($stream);
    }

    /**
     * Assert the next response is a successful tagged response.
     */
    protected function assertTaggedResponse(string $tag, ?callable $exception = null): TaggedResponse
    {
        return $this->assertNextResponse(
            fn (Response $response) => (
                $response instanceof TaggedResponse && $response->tag()->is($tag)
            ),
            fn (TaggedResponse $response) => (
                $response->successful()
            ),
            $exception ?? fn (TaggedResponse $response) => (
                CommandFailedException::make($this->result->command(), $response)
            ),
        );
    }

    /**
     * Assert the next response is an untagged response.
     */
    protected function assertUntaggedResponse(callable $assertion, callable $exception): UntaggedResponse
    {
        return $this->assertNextResponse(fn (Response $response) => (
            $response instanceof UntaggedResponse
        ), $assertion, $exception);
    }

    /**
     * Assert the next response is a continuation response.
     */
    protected function assertContinuationResponse(callable $assertion, callable $exception): ContinuationResponse
    {
        return $this->assertNextResponse(fn (Response $response) => (
            $response instanceof ContinuationResponse
        ), $assertion, $exception);
    }

    /**
     * Assert the next response matches the given filter and assertion.
     *
     * @template T of Response
     *
     * @param  callable(Response): bool  $filter
     * @return T
     */
    protected function assertNextResponse(callable $filter, callable $assertion, callable $exception): Response
    {
        while ($response = $this->nextResponse($filter)) {
            if ($assertion($response)) {
                return $response;
            }

            throw $exception($response);
        }

        throw new RuntimeException('No matching response found.');
    }

    /**
     * Returns the next untagged response.
     */
    protected function nextUntaggedResponse(?callable $filter = null): UntaggedResponse
    {
        return $this->nextResponse(fn (Response $response) => (
            $response instanceof UntaggedResponse && (! $filter || $filter($response))
        ));
    }

    /**
     * Returns the next continuation response.
     */
    protected function nextContinuationResponse(): ContinuationResponse
    {
        return $this->nextResponse(fn (Response $response) => (
            $response instanceof ContinuationResponse
        ));
    }

    /**
     * Returns the next tagged response.
     */
    protected function nextTaggedResponse(string $tag): TaggedResponse
    {
        return $this->nextResponse(fn (Response $response) => (
            $response instanceof TaggedResponse && $response->tag()->is($tag)
        ));
    }

    /**
     * Returns the next response matching the given filter.
     *
     * @template T of Response
     *
     * @param  callable(Response): bool  $filter
     * @return T
     */
    protected function nextResponse(callable $filter): Response
    {
        if (! $this->parser) {
            throw new RuntimeException('No parser instance set.');
        }

        while ($response = $this->parser->next()) {
            if (! $response instanceof Response) {
                continue;
            }

            $this->result?->addResponse($response);

            if ($filter($response)) {
                return $response;
            }
        }

        throw new RuntimeException('No matching response found.');
    }
}
