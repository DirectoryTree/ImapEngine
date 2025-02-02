<?php

namespace DirectoryTree\ImapEngine\Connection;

use RuntimeException;
use DirectoryTree\ImapEngine\Imap;

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
    public function login(string $user, string $password): Response
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? Response::make();
    }

    /**
     * {@inheritDoc}
     */
    public function authenticate(string $user, string $token): Response
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? Response::make();
    }

    /**
     * {@inheritDoc}
     */
    public function logout(): Response
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? Response::make();
    }

    /**
     * {@inheritDoc}
     */
    public function getCapabilities(): Response
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? Response::make();
    }

    /**
     * {@inheritDoc}
     */
    public function selectFolder(string $folder = 'INBOX'): Response
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? Response::make();
    }

    /**
     * {@inheritDoc}
     */
    public function examineFolder(string $folder = 'INBOX'): Response
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? Response::make();
    }

    /**
     * {@inheritDoc}
     */
    public function folderStatus(string $folder = 'INBOX', array $arguments = ['MESSAGES', 'UNSEEN', 'RECENT', 'UIDNEXT', 'UIDVALIDITY']): Response
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? Response::make();
    }

    /**
     * {@inheritDoc}
     */
    public function content(array|int $uids, string $rfc = 'RFC822', int|string $uid = Imap::ST_UID): Response
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? Response::make();
    }

    /**
     * {@inheritDoc}
     */
    public function headers(array|int $uids, string $rfc = 'RFC822', int|string $uid = Imap::ST_UID): Response
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? Response::make();
    }

    /**
     * {@inheritDoc}
     */
    public function flags(array|int $uids, int|string $uid = Imap::ST_UID): Response
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? Response::make();
    }

    /**
     * {@inheritDoc}
     */
    public function sizes(array|int $uids, int|string $uid = Imap::ST_UID): Response
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? Response::make();
    }

    /**
     * {@inheritDoc}
     */
    public function getUid(?int $id = null): Response
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? Response::make();
    }

    /**
     * {@inheritDoc}
     */
    public function getMessageNumber(string $id): Response
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? Response::make();
    }

    /**
     * {@inheritDoc}
     */
    public function folders(string $reference = '', string $folder = '*'): Response
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? Response::make();
    }

    /**
     * {@inheritDoc}
     */
    public function store(array|string $flags, int $from, ?int $to = null, ?string $mode = null, bool $silent = true, int|string $uid = Imap::ST_UID, ?string $item = null): Response
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? Response::make();
    }

    /**
     * {@inheritDoc}
     */
    public function appendMessage(string $folder, string $message, ?array $flags = null, ?string $date = null): Response
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? Response::make();
    }

    /**
     * {@inheritDoc}
     */
    public function copyMessage(string $folder, $from, ?int $to = null, int|string $uid = Imap::ST_UID): Response
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? Response::make();
    }

    /**
     * {@inheritDoc}
     */
    public function copyManyMessages(array $messages, string $folder, int|string $uid = Imap::ST_UID): Response
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? Response::make();
    }

    /**
     * {@inheritDoc}
     */
    public function moveMessage(string $folder, $from, ?int $to = null, int|string $uid = Imap::ST_UID): Response
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? Response::make();
    }

    /**
     * {@inheritDoc}
     */
    public function moveManyMessages(array $messages, string $folder, int|string $uid = Imap::ST_UID): Response
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? Response::make();
    }

    /**
     * {@inheritDoc}
     */
    public function id(?array $ids = null): Response
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? Response::make();
    }

    /**
     * {@inheritDoc}
     */
    public function createFolder(string $folder): Response
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? Response::make();
    }

    /**
     * {@inheritDoc}
     */
    public function renameFolder(string $oldPath, string $newPath): Response
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? Response::make();
    }

    /**
     * {@inheritDoc}
     */
    public function deleteFolder(string $folder): Response
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? Response::make();
    }

    /**
     * {@inheritDoc}
     */
    public function subscribeFolder(string $folder): Response
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? Response::make();
    }

    /**
     * {@inheritDoc}
     */
    public function unsubscribeFolder(string $folder): Response
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? Response::make();
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
    public function expunge(): Response
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? Response::make();
    }

    /**
     * {@inheritDoc}
     */
    public function getQuota(string $username): Response
    {

        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? Response::make();
    }

    /**
     * {@inheritDoc}
     */
    public function getQuotaRoot(string $quotaRoot = 'INBOX'): Response
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? Response::make();
    }

    /**
     * {@inheritDoc}
     */
    public function noop(): Response
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? Response::make();
    }

    /**
     * {@inheritDoc}
     */
    public function search(array $params, int|string $uid = Imap::ST_UID): Response
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? Response::make();
    }

    /**
     * {@inheritDoc}
     */
    public function overview(string $sequence, int|string $uid = Imap::ST_UID): Response
    {
        return $this->getExpectationResponse(__FUNCTION__, func_get_args()) ?? Response::make();
    }
}
