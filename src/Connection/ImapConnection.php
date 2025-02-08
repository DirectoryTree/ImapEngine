<?php

namespace DirectoryTree\ImapEngine\Connection;

use DirectoryTree\ImapEngine\Collections\ResponseCollection;
use DirectoryTree\ImapEngine\Connection\Responses\ContinuationResponse;
use DirectoryTree\ImapEngine\Connection\Responses\Response;
use DirectoryTree\ImapEngine\Connection\Responses\TaggedResponse;
use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
use DirectoryTree\ImapEngine\Exceptions\AuthFailedException;
use DirectoryTree\ImapEngine\Exceptions\RuntimeException;
use DirectoryTree\ImapEngine\Imap;
use Illuminate\Support\Arr;
use Throwable;

class ImapConnection extends Connection
{
    /**
     * The current request sequence.
     */
    protected int $sequence = 0;

    /**
     * {@inheritDoc}
     */
    public function login(string $user, string $password): TaggedResponse
    {
        $this->send('LOGIN', $this->escapeString($user, $password), $tag);

        return $this->assertTaggedResponse($tag, fn () => new AuthFailedException('Failed to login'));
    }

    /**
     * {@inheritDoc}
     */
    public function authenticate(string $user, string $token): TaggedResponse
    {
        $authenticateParams = ['XOAUTH2', base64_encode("user=$user\1auth=Bearer $token\1\1")];

        $this->send('AUTHENTICATE', $authenticateParams, $tag);

        return $this->assertTaggedResponse($tag, fn () => new AuthFailedException('Failed to authenticate'));
    }

    /**
     * {@inheritDoc}
     */
    public function logout(): ?TaggedResponse
    {
        if (! $this->stream->isOpen() || ($this->meta()['timed_out'] ?? false)) {
            $this->close();

            return null;
        }

        try {
            $this->send('LOGOUT', tag: $tag);

            return $this->nextTaggedResponse($tag);
        } catch (Throwable) {
            $result = null;
        }

        $this->close();

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function selectFolder(string $folder = 'INBOX'): ResponseCollection
    {
        return $this->examineOrSelect('SELECT', $folder);
    }

    /**
     * {@inheritDoc}
     */
    public function examineFolder(string $folder = 'INBOX'): ResponseCollection
    {
        return $this->examineOrSelect('EXAMINE', $folder);
    }

    /**
     * Examine and select have the same response.
     *
     * @param  string  $command  can be 'EXAMINE' or 'SELECT'
     * @param  string  $folder  target folder
     */
    protected function examineOrSelect(string $command = 'EXAMINE', string $folder = 'INBOX'): ResponseCollection
    {
        // Send the command and retrieve the tag.
        $this->send($command, [$this->escapeString($folder)], $tag);

        $this->assertTaggedResponse($tag, fn () => new RuntimeException('Failed to select folder'));

        return $this->result->responses()->untagged();
    }

    /**
     * {@inheritDoc}
     */
    public function folderStatus(string $folder = 'INBOX', array $arguments = ['MESSAGES', 'UNSEEN', 'RECENT', 'UIDNEXT', 'UIDVALIDITY']): UntaggedResponse
    {
        $this->send('STATUS', [$this->escapeString($folder), $this->escapeList($arguments)], $tag);

        $this->assertTaggedResponse($tag, fn () => new RuntimeException('Failed to retrieve folder status'));

        return $this->result->responses()->untagged()->firstWhere(
            fn (UntaggedResponse $response) => $response->type()->is('STATUS')
        );
    }

    /**
     * {@inheritDoc}
     */
    public function createFolder(string $folder): ResponseCollection
    {
        $this->send('CREATE', [$this->escapeString($folder)]);
    }

    /**
     * {@inheritDoc}
     */
    public function deleteFolder(string $folder): TaggedResponse
    {
        $this->send('DELETE', [$this->escapeString($folder)], tag: $tag);

        return $this->assertTaggedResponse($tag, fn () => new RuntimeException('Failed to delete folder'));
    }

    /**
     * {@inheritDoc}
     */
    public function renameFolder(string $oldPath, string $newPath): TaggedResponse
    {
        $this->send('RENAME', $this->escapeString($oldPath, $newPath), tag: $tag);

        return $this->assertTaggedResponse($tag, fn () => new RuntimeException('Failed to rename folder'));
    }

    /**
     * {@inheritDoc}
     */
    public function subscribeFolder(string $folder): TaggedResponse
    {
        $this->send('SUBSCRIBE', [$this->escapeString($folder)], tag: $tag);

        return $this->assertTaggedResponse($tag, fn () => new RuntimeException('Failed to subscribe to folder'));
    }

    /**
     * {@inheritDoc}
     */
    public function unsubscribeFolder(string $folder): TaggedResponse
    {
        $this->send('UNSUBSCRIBE', [$this->escapeString($folder)], tag: $tag);

        return $this->assertTaggedResponse($tag, fn () => new RuntimeException('Failed to subscribe to folder'));
    }

    /**
     * {@inheritDoc}
     */
    public function folders(string $reference = '', string $folder = '*'): ResponseCollection
    {
        $this->send('LIST', $this->escapeString($reference, $folder), $tag);

        $this->assertTaggedResponse($tag, fn () => new RuntimeException('Failed to fetch folders'));

        return $this->result->responses()->untagged()->filter(
            fn (UntaggedResponse $response) => $response->type()->is('LIST')
        );
    }

    /**
     * {@inheritDoc}
     */
    public function appendMessage(string $folder, string $message, ?array $flags = null, ?string $date = null): ResponseCollection
    {
        $tokens = [];

        $tokens[] = $this->escapeString($folder);

        if ($flags !== null) {
            $tokens[] = $this->escapeList($flags);
        }

        if ($date !== null) {
            $tokens[] = $this->escapeString($date);
        }

        $tokens[] = $this->escapeString($message);

        return $this->send('APPEND', $tokens);
    }

    /**
     * {@inheritDoc}
     */
    public function copyMessage(string $folder, $from, ?int $to = null): ResponseCollection
    {
        return $this->send('UID COPY', [
            $this->buildSet($from, $to),
            $this->escapeString($folder),
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function copyManyMessages(array $messages, string $folder): ResponseCollection
    {
        $set = implode(',', $messages);

        $tokens = [$set, $this->escapeString($folder)];

        $this->send('UID COPY', $tokens);
    }

    /**
     * {@inheritDoc}
     */
    public function moveMessage(string $folder, $from, ?int $to = null): ResponseCollection
    {
        $set = $this->buildSet($from, $to);

        return $this->send('UID MOVE', [$set, $this->escapeString($folder)]);
    }

    /**
     * {@inheritDoc}
     */
    public function moveManyMessages(array $messages, string $folder): ResponseCollection
    {
        $set = implode(',', $messages);

        $tokens = [$set, $this->escapeString($folder)];

        return $this->send('UID MOVE', $tokens);
    }

    /**
     * {@inheritDoc}
     */
    public function store(array|string $flags, int $from, ?int $to = null, ?string $mode = null, bool $silent = true, ?string $item = null): ResponseCollection
    {
        $set = $this->buildSet($from, $to);

        $flags = $this->escapeList(Arr::wrap($flags));

        $item = ($mode == '-' ? '-' : '+').(is_null($item) ? 'FLAGS' : $item).($silent ? '.SILENT' : '');

        $this->send('UID STORE', [$set, $item, $flags], tag: $tag);

        $this->assertTaggedResponse($tag, fn () => new RuntimeException('Failed to store flags'));

        return $silent ? new ResponseCollection : $this->result->responses()->untagged()->filter(
            fn (UntaggedResponse $response) => $response->type()->is('FETCH')
        );
    }

    /**
     * {@inheritDoc}
     */
    public function uids(int|array $msgns): ResponseCollection
    {
        return $this->fetch(['UID'], (array) $msgns, null, Imap::SEQUENCE_TYPE_MSG_NUMBER);
    }

    /**
     * {@inheritDoc}
     */
    public function contents(int|array $ids, bool $peek = true): ResponseCollection
    {
        return $this->fetch([$peek ? 'BODY.PEEK[TEXT]' : 'BODY[TEXT]'], (array) $ids);
    }

    /**
     * {@inheritDoc}
     */
    public function headers(int|array $ids, bool $peek = true): ResponseCollection
    {
        return $this->fetch([$peek ? 'BODY.PEEK[HEADER]' : 'BODY[HEADER]'], (array) $ids);
    }

    /**
     * {@inheritDoc}
     */
    public function flags(int|array $ids): ResponseCollection
    {
        return $this->fetch(['FLAGS'], (array) $ids);
    }

    /**
     * {@inheritDoc}
     */
    public function sizes(int|array $ids): ResponseCollection
    {
        return $this->fetch(['RFC822.SIZE'], (array) $ids);
    }

    /**
     * {@inheritDoc}
     */
    public function search(array $params): UntaggedResponse
    {
        $this->send('UID SEARCH', $params, tag: $tag);

        $this->assertTaggedResponse($tag, fn () => new RuntimeException('Failed to search messages'));

        return $this->result->responses()->untagged()->firstOrFail(
            fn (UntaggedResponse $response) => $response->type()->is('SEARCH')
        );
    }

    /**
     * {@inheritDoc}
     */
    public function capability(): UntaggedResponse
    {
        $this->send('CAPABILITY', tag: $tag);

        $this->assertTaggedResponse($tag, fn () => new RuntimeException('Failed to fetch capabilities'));

        return $this->result->responses()->untagged()->firstOrFail(
            fn (UntaggedResponse $response) => $response->type()->is('CAPABILITY')
        );
    }

    /**
     * {@inheritDoc}
     */
    public function id(?array $ids = null): UntaggedResponse
    {
        $token = 'NIL';

        if (is_array($ids) && ! empty($ids)) {
            $token = '(';

            foreach ($ids as $id) {
                $token .= '"'.$id.'" ';
            }

            $token = rtrim($token).')';
        }

        $this->send('ID', [$token], tag: $tag);

        $this->assertTaggedResponse($tag, fn () => new RuntimeException('Failed to send ID'));

        return $this->result->responses()->untagged()->firstOrFail(
            fn (UntaggedResponse $response) => $response->type()->is('CAPABILITY')
        );
    }

    /**
     * {@inheritDoc}
     */
    public function expunge(): ResponseCollection
    {
        $this->send('EXPUNGE', tag: $tag);

        $this->assertTaggedResponse($tag, fn () => new RuntimeException('Failed to expunge messages'));

        return $this->result->responses()->untagged();
    }

    /**
     * {@inheritDoc}
     */
    public function noop(): TaggedResponse
    {
        $this->send('NOOP', tag: $tag);

        return $this->assertTaggedResponse($tag, fn () => new RuntimeException('Failed to send NOOP'));
    }

    /**
     * {@inheritDoc}
     */
    public function idle(): void
    {
        $this->send('IDLE', tag: $tag);

        $this->assertContinuationResponse(
            fn (ContinuationResponse $response) => true,
            fn () => new RuntimeException('Failed to send IDLE')
        );
    }

    /**
     * {@inheritDoc}
     */
    public function done(): void
    {
        $this->write('DONE');

        $this->assertNextResponse(
            fn (Response $response) => $response instanceof TaggedResponse,
            fn (TaggedResponse $response) => $response->successful(),
            fn () => new RuntimeException('Failed to send DONE')
        );
    }

    /**
     * Build a UID number set.
     */
    public function buildSet($from, $to = null): int|string
    {
        $set = (int) $from;

        if ($to !== null) {
            $set .= ':'.($to == INF ? '*' : (int) $to);
        }

        return $set;
    }
}
