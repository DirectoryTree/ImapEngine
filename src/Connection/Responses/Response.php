<?php

namespace DirectoryTree\ImapEngine\Connection\Responses;

use Exception;
use Stringable;

abstract class Response implements Stringable
{
    /**
     * Constructor.
     */
    public function __construct(
        protected array $tokens,
    ) {}

    /**
     * Collect responses from an array of tokens.
     *
     * @return Response[]
     */
    public static function collect(array $tokens): array
    {
        $responses = [];
        $response = [];

        foreach ($tokens as $token) {
            if (static::isResponseStart($token)) {
                // If there's an existing group, flush it before starting a new one.
                if (! empty($response)) {
                    $responses[] = static::make($response);

                    $response = [];
                }
            }

            $response[] = $token;
        }

        // Flush any remaining tokens.
        if (! empty($response)) {
            $responses[] = static::make($response);
        }

        return $responses;
    }

    /**
     * Determine if the token is the start of a new response.
     */
    protected static function isResponseStart(mixed $token): bool
    {
        if (! is_string($token)) {
            return false;
        }

        return $token === '*' || $token === '+' || str_starts_with($token, 'TAG');
    }

    /**
     * Create an appropriate response object based on the first token.
     */
    protected static function make(array $tokens): Response
    {
        if (empty($tokens)) {
            throw new Exception('Cannot create a response from an empty token array');
        }

        $first = $tokens[0];

        if ($first === '*') {
            return new UntaggedResponse($tokens);
        }

        if ($first === '+') {
            return new ContinuationResponse($tokens);
        }

        return new TaggedResponse($tokens);
    }

    /**
     * Get the response tokens.
     */
    public function tokens(): array
    {
        return $this->tokens;
    }

    /**
     * Get a JSON representation of the response tokens.
     */
    public function __toString(): string
    {
        return json_encode($this->tokens);
    }
}
