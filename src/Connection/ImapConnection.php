<?php

namespace DirectoryTree\ImapEngine\Connection;

use DirectoryTree\ImapEngine\Collections\ResponseCollection;
use DirectoryTree\ImapEngine\Connection\Responses\ContinuationResponse;
use DirectoryTree\ImapEngine\Connection\Responses\Response;
use DirectoryTree\ImapEngine\Connection\Responses\TaggedResponse;
use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
use DirectoryTree\ImapEngine\Exceptions\CommandFailedException;
use DirectoryTree\ImapEngine\Exceptions\ConnectionFailedException;
use DirectoryTree\ImapEngine\Exceptions\Exception;
use DirectoryTree\ImapEngine\Support\Str;

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
        $this->send('LOGIN', Str::literal([$user, $password]), $tag);

        return $this->assertTaggedResponse($tag);
    }

    /**
     * {@inheritDoc}
     */
    public function authenticate(string $user, string $token): TaggedResponse
    {
        $credentials = base64_encode("user=$user\1auth=Bearer $token\1\1");

        $this->send('AUTHENTICATE', ['XOAUTH2', $credentials], $tag);

        return $this->assertTaggedResponse($tag);
    }

    /**
     * {@inheritDoc}
     */
    public function startTls(): void
    {
        $this->send('STARTTLS', tag: $tag);

        $this->assertTaggedResponse($tag, fn () => (
            new ConnectionFailedException('Failed to enable STARTTLS')
        ));

        $this->stream->setSocketSetCrypto(true, $this->getCryptoMethod());
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
        } catch (Exception) {
            // Do nothing.
        }

        $this->close();

        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function select(string $folder = 'INBOX'): ResponseCollection
    {
        return $this->examineOrSelect('SELECT', $folder);
    }

    /**
     * {@inheritDoc}
     */
    public function examine(string $folder = 'INBOX'): ResponseCollection
    {
        return $this->examineOrSelect('EXAMINE', $folder);
    }

    /**
     * Examine and select have the same response.
     */
    protected function examineOrSelect(string $command = 'EXAMINE', string $folder = 'INBOX'): ResponseCollection
    {
        $this->send($command, [Str::literal($folder)], $tag);

        $this->assertTaggedResponse($tag);

        return $this->result->responses()->untagged();
    }

    /**
     * {@inheritDoc}
     */
    public function status(string $folder = 'INBOX', array $arguments = ['MESSAGES', 'UNSEEN', 'RECENT', 'UIDNEXT', 'UIDVALIDITY']): UntaggedResponse
    {
        $this->send('STATUS', [
            Str::literal($folder),
            Str::list($arguments),
        ], $tag);

        $this->assertTaggedResponse($tag);

        return $this->result->responses()->untagged()->firstWhere(
            fn (UntaggedResponse $response) => $response->type()->is('STATUS')
        );
    }

    /**
     * {@inheritDoc}
     */
    public function create(string $folder): ResponseCollection
    {
        $this->send('CREATE', [Str::literal($folder)], $tag);

        $this->assertTaggedResponse($tag);

        return $this->result->responses()->untagged()->filter(
            fn (UntaggedResponse $response) => $response->type()->is('LIST')
        );
    }

    /**
     * {@inheritDoc}
     */
    public function delete(string $folder): TaggedResponse
    {
        $this->send('DELETE', [Str::literal($folder)], tag: $tag);

        return $this->assertTaggedResponse($tag);
    }

    /**
     * {@inheritDoc}
     */
    public function rename(string $oldPath, string $newPath): TaggedResponse
    {
        $this->send('RENAME', Str::literal([$oldPath, $newPath]), tag: $tag);

        return $this->assertTaggedResponse($tag);
    }

    /**
     * {@inheritDoc}
     */
    public function subscribe(string $folder): TaggedResponse
    {
        $this->send('SUBSCRIBE', [Str::literal($folder)], tag: $tag);

        return $this->assertTaggedResponse($tag);
    }

    /**
     * {@inheritDoc}
     */
    public function unsubscribe(string $folder): TaggedResponse
    {
        $this->send('UNSUBSCRIBE', [Str::literal($folder)], tag: $tag);

        return $this->assertTaggedResponse($tag);
    }

    /**
     * {@inheritDoc}
     */
    public function list(string $reference = '', string $folder = '*'): ResponseCollection
    {
        $this->send('LIST', Str::literal([$reference, $folder]), $tag);

        $this->assertTaggedResponse($tag);

        return $this->result->responses()->untagged()->filter(
            fn (UntaggedResponse $response) => $response->type()->is('LIST')
        );
    }

    /**
     * {@inheritDoc}
     */
    public function append(string $folder, string $message, ?array $flags = null, ?string $date = null): ResponseCollection
    {
        $tokens = [];

        $tokens[] = Str::literal($folder);

        if ($flags) {
            $tokens[] = Str::list($flags);
        }

        if ($date) {
            $tokens[] = Str::literal($date);
        }

        $tokens[] = Str::literal($message);

        $this->send('APPEND', $tokens, tag: $tag);

        $this->assertTaggedResponse($tag);

        return $this->result->responses()->untagged()->filter(
            fn (UntaggedResponse $response) => $response->type()->is('LIST')
        );
    }

    /**
     * {@inheritDoc}
     */
    public function copy(string $folder, array|int $from, ?int $to = null): void
    {
        $this->send('UID COPY', [
            Str::set($from, $to),
            Str::literal($folder),
        ], $tag);

        $this->assertTaggedResponse($tag);
    }

    /**
     * {@inheritDoc}
     */
    public function move(string $folder, array|int $from, ?int $to = null): void
    {
        $this->send('UID MOVE', [
            Str::set($from, $to),
            Str::literal($folder),
        ], $tag);

        $this->assertTaggedResponse($tag);
    }

    /**
     * {@inheritDoc}
     */
    public function store(array|string $flags, array|int $from, ?int $to = null, ?string $mode = null, bool $silent = true, ?string $item = null): ResponseCollection
    {
        $set = Str::set($from, $to);

        $flags = Str::list((array) $flags);

        $item = ($mode == '-' ? '-' : '+').(is_null($item) ? 'FLAGS' : $item).($silent ? '.SILENT' : '');

        $this->send('UID STORE', [$set, $item, $flags], tag: $tag);

        $this->assertTaggedResponse($tag);

        return $silent ? new ResponseCollection : $this->result->responses()->untagged()->filter(
            fn (UntaggedResponse $response) => $response->type()->is('FETCH')
        );
    }

    /**
     * {@inheritDoc}
     */
    public function uid(int|array $msgns): ResponseCollection
    {
        return $this->fetch(['UID'], (array) $msgns, null, ImapFetchIdentifier::MessageNumber);
    }

    /**
     * {@inheritDoc}
     */
    public function text(int|array $ids, bool $peek = true): ResponseCollection
    {
        return $this->fetch([$peek ? 'BODY.PEEK[TEXT]' : 'BODY[TEXT]'], (array) $ids);
    }

    /**
     * {@inheritDoc}
     */
    public function header(int|array $ids, bool $peek = true): ResponseCollection
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

        $this->assertTaggedResponse($tag);

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

        $this->assertTaggedResponse($tag);

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

        $this->assertTaggedResponse($tag);

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

        $this->assertTaggedResponse($tag);

        return $this->result->responses()->untagged();
    }

    /**
     * {@inheritDoc}
     */
    public function noop(): TaggedResponse
    {
        $this->send('NOOP', tag: $tag);

        return $this->assertTaggedResponse($tag);
    }

    /**
     * {@inheritDoc}
     */
    public function idle(): void
    {
        $this->send('IDLE', tag: $tag);

        $this->assertContinuationResponse(
            fn (ContinuationResponse $response) => true,
            fn (ContinuationResponse $response) => CommandFailedException::make(new ImapCommand('', 'IDLE'), $response),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function done(): void
    {
        $this->write('DONE');

        // After issuing a "DONE" command, the server must eventually respond with a
        // tagged response to indicate that the IDLE command has been successfully
        // terminated and the server is ready to accept further commands.
        $this->assertNextResponse(
            fn (Response $response) => $response instanceof TaggedResponse,
            fn (TaggedResponse $response) => $response->successful(),
            fn (TaggedResponse $response) => CommandFailedException::make(new ImapCommand('', 'DONE'), $response),
        );
    }
}
