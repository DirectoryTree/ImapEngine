<?php

namespace DirectoryTree\ImapEngine\Connection;

use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
use RuntimeException;

/**
 * Example usage:
 *
 *   $fake = new AssertableFakeConnection;
 *   $fake->expect('connect', ['imap.test.dev', 993], Response::make());
 *   $fake->connect('imap.test.dev', 993);
 *   $fake->assertCalled('connect'); // passes
 *   $fake->assertCalledTimes('connect', 1); // passes
 */
class FakeConnection extends Connection
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
     * Attempt to get the pre-configured response for the given method and arguments.
     */
    protected function getExpectationResponse(string $method, mixed $args): mixed
    {
        if (
            ! isset($this->expectations[$method])) {
            throw new RuntimeException("No expectations set for method [$method].");
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
    public function connect(string $host, ?int $port = null): void
    {
        $this->getExpectationResponse(__FUNCTION__, func_get_args());
    }

    /**
     * {@inheritDoc}
     */
    public function login(string $user, string $password): ResponseCollection
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? ResponseCollection::make();
    }

    /**
     * {@inheritDoc}
     */
    public function authenticate(string $user, string $token): ResponseCollection
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? ResponseCollection::make();
    }

    /**
     * {@inheritDoc}
     */
    public function logout(): ResponseCollection
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? ResponseCollection::make();
    }

    /**
     * {@inheritDoc}
     */
    public function capability(): UntaggedResponse
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? ResponseCollection::make();
    }

    /**
     * {@inheritDoc}
     */
    public function select(string $folder = 'INBOX'): ResponseCollection
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? ResponseCollection::make();
    }

    /**
     * {@inheritDoc}
     */
    public function examine(string $folder = 'INBOX'): ResponseCollection
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? ResponseCollection::make();
    }

    /**
     * {@inheritDoc}
     */
    public function status(string $folder = 'INBOX', array $arguments = ['MESSAGES', 'UNSEEN', 'RECENT', 'UIDNEXT', 'UIDVALIDITY']): ResponseCollection
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? ResponseCollection::make();
    }

    /**
     * {@inheritDoc}
     */
    public function uid(int|array $msgns): ResponseCollection
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? ResponseCollection::make();
    }

    /**
     * {@inheritDoc}
     */
    public function text(array|int $ids): ResponseCollection
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? ResponseCollection::make();
    }

    /**
     * {@inheritDoc}
     */
    public function header(array|int $ids): ResponseCollection
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? ResponseCollection::make();
    }

    /**
     * {@inheritDoc}
     */
    public function flags(array|int $ids): ResponseCollection
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? ResponseCollection::make();
    }

    /**
     * {@inheritDoc}
     */
    public function sizes(array|int $ids): ResponseCollection
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? ResponseCollection::make();
    }

    /**
     * {@inheritDoc}
     */
    public function list(string $reference = '', string $folder = '*'): ResponseCollection
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? ResponseCollection::make();
    }

    /**
     * {@inheritDoc}
     */
    public function store(array|string $flags, int $from, ?int $to = null, ?string $mode = null, bool $silent = true, ?string $item = null): ResponseCollection
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? ResponseCollection::make();
    }

    /**
     * {@inheritDoc}
     */
    public function append(string $folder, string $message, ?array $flags = null, ?string $date = null): ResponseCollection
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? ResponseCollection::make();
    }

    /**
     * {@inheritDoc}
     */
    public function copy(string $folder, $from, ?int $to = null): ResponseCollection
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? ResponseCollection::make();
    }

    /**
     * {@inheritDoc}
     */
    public function copyManyMessages(array $messages, string $folder): ResponseCollection
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? ResponseCollection::make();
    }

    /**
     * {@inheritDoc}
     */
    public function move(string $folder, $from, ?int $to = null): ResponseCollection
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? ResponseCollection::make();
    }

    /**
     * {@inheritDoc}
     */
    public function moveManyMessages(array $messages, string $folder): ResponseCollection
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? ResponseCollection::make();
    }

    /**
     * {@inheritDoc}
     */
    public function id(?array $ids = null): ResponseCollection
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? ResponseCollection::make();
    }

    /**
     * {@inheritDoc}
     */
    public function create(string $folder): ResponseCollection
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? ResponseCollection::make();
    }

    /**
     * {@inheritDoc}
     */
    public function rename(string $oldPath, string $newPath): ResponseCollection
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? ResponseCollection::make();
    }

    /**
     * {@inheritDoc}
     */
    public function delete(string $folder): ResponseCollection
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? ResponseCollection::make();
    }

    /**
     * {@inheritDoc}
     */
    public function subscribe(string $folder): ResponseCollection
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? ResponseCollection::make();
    }

    /**
     * {@inheritDoc}
     */
    public function unsubscribe(string $folder): ResponseCollection
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? ResponseCollection::make();
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
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? ResponseCollection::make();
    }

    /**
     * {@inheritDoc}
     */
    public function noop(): ResponseCollection
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? ResponseCollection::make();
    }

    /**
     * {@inheritDoc}
     */
    public function search(array $params): ResponseCollection
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? ResponseCollection::make();
    }
}
