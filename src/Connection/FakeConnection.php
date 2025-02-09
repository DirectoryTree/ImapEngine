<?php

namespace DirectoryTree\ImapEngine\Connection;

use DirectoryTree\ImapEngine\Collections\ResponseCollection;
use DirectoryTree\ImapEngine\Connection\Responses\TaggedResponse;
use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
use RuntimeException;

class FakeConnection implements ConnectionInterface
{
    /**
     * All calls recorded, keyed by method name.
     *
     * @var array<string, array<int, array>>
     */
    protected array $calls = [];

    /**
     * The expectations keyed by method name.
     *
     * @var array<string, array<int, array{matcher: callable|array, response: mixed}>>
     */
    protected array $expectations = [];

    /**
     * Register an expectation for a given method.
     */
    public function expect(string $method, callable|array $matcher, mixed $response): self
    {
        $this->expectations[$method][] = [
            'matcher' => $matcher,
            'response' => $response,
        ];

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function connect(string $host, ?int $port = null, array $options = []): void
    {
        $this->getExpectationResponse(__FUNCTION__, func_get_args());
    }

    /**
     * {@inheritDoc}
     */
    public function login(string $user, string $password): TaggedResponse
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args());
    }

    /**
     * {@inheritDoc}
     */
    public function authenticate(string $user, string $token): TaggedResponse
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args());
    }

    /**
     * {@inheritDoc}
     */
    public function connected(): bool {}

    /**
     * {@inheritDoc}
     */
    public function startTls(): void
    {
        $this->getExpectationResponse(__FUNCTION__, func_get_args());
    }

    /**
     * {@inheritDoc}
     */
    public function logout(): void
    {
        $this->getExpectationResponse(__FUNCTION__, func_get_args());
    }

    /**
     * {@inheritDoc}
     */
    public function capability(): UntaggedResponse
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args());
    }

    /**
     * {@inheritDoc}
     */
    public function select(string $folder = 'INBOX'): ResponseCollection
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args());
    }

    /**
     * {@inheritDoc}
     */
    public function examine(string $folder = 'INBOX'): ResponseCollection
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args());
    }

    /**
     * {@inheritDoc}
     */
    public function status(string $folder = 'INBOX', array $arguments = ['MESSAGES', 'UNSEEN', 'RECENT', 'UIDNEXT', 'UIDVALIDITY']): UntaggedResponse
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args());
    }

    /**
     * {@inheritDoc}
     */
    public function uid(int|array $msgns): ResponseCollection
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args());
    }

    /**
     * {@inheritDoc}
     */
    public function text(array|int $ids, bool $peek = true): ResponseCollection
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args());
    }

    /**
     * {@inheritDoc}
     */
    public function header(array|int $ids, bool $peek = true): ResponseCollection
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args());
    }

    /**
     * {@inheritDoc}
     */
    public function flags(array|int $ids): ResponseCollection
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args());
    }

    /**
     * {@inheritDoc}
     */
    public function sizes(array|int $ids): ResponseCollection
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args());
    }

    /**
     * {@inheritDoc}
     */
    public function list(string $reference = '', string $folder = '*'): ResponseCollection
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args());
    }

    /**
     * {@inheritDoc}
     */
    public function store(array|string $flags, int $from, ?int $to = null, ?string $mode = null, bool $silent = true, ?string $item = null): ResponseCollection
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args());
    }

    /**
     * {@inheritDoc}
     */
    public function append(string $folder, string $message, ?array $flags = null, ?string $date = null): ResponseCollection
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args());
    }

    /**
     * {@inheritDoc}
     */
    public function copy(string $folder, $from, ?int $to = null): void
    {
        $this->getExpectationResponse(__FUNCTION__, func_get_args());
    }

    /**
     * {@inheritDoc}
     */
    public function move(string $folder, $from, ?int $to = null): void
    {
        $this->getExpectationResponse(__FUNCTION__, func_get_args());
    }

    /**
     * {@inheritDoc}
     */
    public function moveManyMessages(array $messages, string $folder): ResponseCollection
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args());
    }

    /**
     * {@inheritDoc}
     */
    public function id(?array $ids = null): UntaggedResponse
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args());
    }

    /**
     * {@inheritDoc}
     */
    public function create(string $folder): ResponseCollection
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args());
    }

    /**
     * {@inheritDoc}
     */
    public function rename(string $oldPath, string $newPath): TaggedResponse
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args());
    }

    /**
     * {@inheritDoc}
     */
    public function delete(string $folder): TaggedResponse
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args());
    }

    /**
     * {@inheritDoc}
     */
    public function subscribe(string $folder): TaggedResponse
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args());
    }

    /**
     * {@inheritDoc}
     */
    public function unsubscribe(string $folder): TaggedResponse
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args());
    }

    /**
     * {@inheritDoc}
     */
    public function idle(): void
    {
        $this->getExpectationResponse(__FUNCTION__, func_get_args());
    }

    /**
     * {@inheritDoc}
     */
    public function done(): void
    {
        $this->getExpectationResponse(__FUNCTION__, func_get_args());
    }

    /**
     * {@inheritDoc}
     */
    public function expunge(): ResponseCollection
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args());
    }

    /**
     * {@inheritDoc}
     */
    public function noop(): TaggedResponse
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args());
    }

    /**
     * {@inheritDoc}
     */
    public function search(array $params): UntaggedResponse
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args());
    }

    /**
     * Attempt to get the pre-configured response for the given method and arguments.
     */
    protected function getExpectationResponse(string $method, mixed $args): mixed
    {
        if (
            ! isset($this->expectations[$method])) {
            throw new RuntimeException("No expectations set for method [$method]");
        }

        foreach ($this->expectations[$method] as $index => $expectation) {
            if ($this->matches($expectation['matcher'], $args)) {
                unset($this->expectations[$method][$index]);

                return $expectation['response'];
            }
        }

        return null;
    }

    /**
     * Determine if the given matcher matches the given arguments.
     */
    protected function matches(callable|array $matcher, array $args): bool
    {
        if (is_array($matcher)) {
            return $matcher == $args;
        }

        // Otherwise, assume it's a callable:
        return (bool) call_user_func($matcher, $args);
    }
}
