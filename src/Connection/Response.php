<?php

namespace DirectoryTree\ImapEngine\Connection;

use Illuminate\Support\Arr;
use DirectoryTree\ImapEngine\Exceptions\ResponseException;

class Response
{
    /**
     * The sequence number to identify the response.
     */
    protected int $sequence = 0;

    /**
     * Whether debugging is enabled.
     */
    protected bool $debug = false;

    /**
     * The result to be returned.
     */
    protected mixed $result = null;

    /**
     * The commands used to fetch or manipulate data.
     */
    protected array $commands = [];

    /**
     * The original response.
     */
    protected array $response = [];

    /**
     * The stack of other related responses.
     */
    protected array $responses = [];

    /**
     * Errors that have occurred while fetching or parsing the response.
     */
    protected array $errors = [];

    /**
     * Whether to allow empty responses.
     */
    protected bool $canBeEmpty = false;

    /**
     * Constructor.
     */
    public function __construct(int $sequence = 0, bool $debug = false)
    {
        $this->debug = $debug;
        $this->sequence = $sequence > 0 ? $sequence : $this->getUniqueSequence();
    }

    /**
     * Make a new response instance.
     */
    public static function make(int $sequence = 0, array $commands = [], array $responses = [], bool $debug = false): Response
    {
        return (new self($sequence, $debug))
            ->setCommands($commands)
            ->setResponse($responses);
    }

    /**
     * Get a unique sequence number.
     */
    protected function getUniqueSequence(): int
    {
        return (int) str_replace('.', '', (string) microtime(true));
    }

    /**
     * Create a new empty response.
     */
    public static function empty(bool $debug = false): Response
    {
        return new self(0, $debug);
    }

    /**
     * Add a new response to the stack.
     */
    public function addResponse(Response $response): void
    {
        $this->responses[] = $response;
    }

    /**
     * Get the response stack.
     */
    public function getResponses(): array
    {
        return $this->responses;
    }

    /**
     * Get all the commands from the response.
     */
    public function getCommands(): array
    {
        return $this->commands;
    }

    /**
     * Add a new command to the response.
     */
    public function addCommand(string $command): Response
    {
        $this->commands[] = $command;

        return $this;
    }

    /**
     * Set the commands on the response.
     */
    public function setCommands(array $commands): Response
    {
        $this->commands = $commands;

        return $this;
    }

    /**
     * Get all errors from the response.
     */
    public function getErrors(): array
    {
        $errors = $this->errors;

        foreach ($this->getResponses() as $response) {
            $errors = array_merge($errors, $response->getErrors());
        }

        return $errors;
    }

    /**
     * Set errors on the response.
     */
    public function setErrors(array $errors): Response
    {
        $this->errors = $errors;

        return $this;
    }

    /**
     * Add an error to the response.
     */
    public function addError(string $error): Response
    {
        $this->errors[] = $error;

        return $this;
    }

    /**
     * Push an IMAP response.
     *
     * @param  array  $response
     */
    public function push(mixed $response): Response
    {
        $this->response[] = $response;

        return $this;
    }

    /**
     * Set the response.
     */
    public function setResponse(array $response): Response
    {
        $this->response = $response;

        return $this;
    }

    /**
     * Get the response.
     */
    public function getResponse(): array
    {
        return $this->response;
    }

    /**
     * Set the result on the response.
     */
    public function setResult(mixed $result): Response
    {
        $this->result = $result;

        return $this;
    }

    /**
     * Get the response data.
     */
    public function data(): mixed
    {
        if (! is_null($this->result)) {
            return $this->result;
        }

        return $this->getResponse();
    }

    /**
     * Get the response data as array.
     */
    public function array(): array
    {
        return Arr::wrap($this->data());
    }

    /**
     * Get the response data as string.
     */
    public function string(): string
    {
        return implode(' ', $this->array());
    }

    /**
     * Get the response data as integer.
     */
    public function integer(): int
    {
        $data = $this->data();

        if (is_array($data) && isset($data[0])) {
            return (int) $data[0];
        }

        return (int) $data;
    }

    /**
     * Get the response data as boolean.
     */
    public function boolean(): bool
    {
        return (bool) $this->data();
    }

    /**
     * Validate and retrieve the response data.
     */
    public function getValidatedData(): mixed
    {
        return $this->validate()->data();
    }

    /**
     * Validate the response data or throw an exception.
     */
    public function validate(): Response
    {
        if ($this->failed()) {
            throw ResponseException::make($this, $this->debug);
        }

        return $this;
    }

    /**
     * Check if the response can be considered successful.
     */
    public function successful(): bool
    {
        foreach (array_merge($this->getResponse(), $this->array()) as $lines) {
            if (! $this->isSuccessful($lines)) {
                return false;
            }
        }

        foreach ($this->getResponses() as $response) {
            if (! $response->successful()) {
                return false;
            }
        }

        return ($this->boolean() || $this->canBeEmpty()) && ! $this->getErrors();
    }

    /**
     * Determine if the given lines are successful.
     */
    protected function isSuccessful(mixed $lines): bool
    {
        if (! is_array($lines)) {
            return static::isLineSuccessful($this->sequence, (string) $lines);
        }

        foreach ($lines as $line) {
            $successful = is_array($line)
                ? $this->isSuccessful($line)
                : static::isLineSuccessful($this->sequence, (string) $line);

            if (! $successful) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine if the given line is successful.
     */
    public static function isLineSuccessful(int $sequence, string $line): bool
    {
        foreach (['BAD', 'NO'] as $error) {
            if (preg_match("/^TAG{$sequence}\s*{$error}\b/i", $line)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if the response contains failures.
     */
    public function failed(): bool
    {
        return ! $this->successful();
    }

    /**
     * Get the response sequence.
     */
    public function sequence(): int
    {
        return $this->sequence;
    }

    /**
     * Set whether the response can be empty.
     */
    public function setCanBeEmpty(bool $canBeEmpty): Response
    {
        $this->canBeEmpty = $canBeEmpty;

        return $this;
    }

    /**
     * Determine if the response can be empty.
     */
    public function canBeEmpty(): bool
    {
        return $this->canBeEmpty;
    }
}
