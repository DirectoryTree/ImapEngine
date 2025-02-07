<?php

namespace DirectoryTree\ImapEngine\Connection;

use DirectoryTree\ImapEngine\Collections\ResponseCollection;
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

        return $this->assertTaggedResponse($tag, fn () => throw new AuthFailedException('Failed to login'));
    }

    /**
     * {@inheritDoc}
     */
    public function authenticate(string $user, string $token): TaggedResponse
    {
        $authenticateParams = ['XOAUTH2', base64_encode("user=$user\1auth=Bearer $token\1\1")];

        $this->send('AUTHENTICATE', $authenticateParams, $tag);

        return $this->assertTaggedResponse($tag, fn () => throw new AuthFailedException('Failed to authenticate'));
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
            $this->send('LOGOUT', [], $tag);

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
        $response = $this->send($command, [$this->escapeString($folder)], $tag);

        $result = [];
        $tokens = [];

        // Continuously read lines associate with the tag to collect the final response.
        while (! $this->readLine($response, $tokens, $tag)) {
            if ($tokens[0] == 'FLAGS') {
                array_shift($tokens);

                $result['flags'] = $tokens;

                continue;
            }

            switch ($tokens[1]) {
                case 'EXISTS':
                case 'RECENT':
                    $result[strtolower($tokens[1])] = (int) $tokens[0];
                    break;
                case '[UIDVALIDITY':
                    $result['uidvalidity'] = (int) $tokens[2];
                    break;
                case '[UIDNEXT':
                    $result['uidnext'] = (int) $tokens[2];
                    break;
                case '[UNSEEN':
                    $result['unseen'] = (int) $tokens[2];
                    break;
                case '[NONEXISTENT]':
                    throw new RuntimeException("Folder doesn't exist");
                default:
                    // ignore
                    break;
            }
        }

        $response->setResult($result);

        if ($tokens[0] != 'OK') {
            $response->addError('request failed');
        }

        return $response;
    }

    /**
     * {@inheritDoc}
     */
    public function folderStatus(string $folder = 'INBOX', array $arguments = ['MESSAGES', 'UNSEEN', 'RECENT', 'UIDNEXT', 'UIDVALIDITY']): ResponseCollection
    {
        $this->send('STATUS', [$this->escapeString($folder), $this->escapeList($arguments)], $tag);

        $data = $response->getValidatedData();

        if (! isset($data[0]) || ! isset($data[0][2])) {
            throw new RuntimeException('Folder status could not be fetched');
        }

        $result = [];

        $key = null;

        foreach ($data[0][2] as $value) {
            if (is_null($key)) {
                $key = $value;
            } else {
                $result[strtolower($key)] = (int) $value;
                $key = null;
            }
        }

        $response->setResult($result);

        return $response;
    }

    /**
     * {@inheritDoc}
     */
    public function createFolder(string $folder): ResponseCollection
    {
        return $this->requestAndResponse('CREATE', [$this->escapeString($folder)], false);
    }

    /**
     * {@inheritDoc}
     */
    public function renameFolder(string $oldPath, string $newPath): ResponseCollection
    {
        return $this->requestAndResponse('RENAME', $this->escapeString($oldPath, $newPath), false);
    }

    /**
     * {@inheritDoc}
     */
    public function deleteFolder(string $folder): ResponseCollection
    {
        return $this->requestAndResponse('DELETE', [$this->escapeString($folder)], false);
    }

    /**
     * {@inheritDoc}
     */
    public function subscribeFolder(string $folder): ResponseCollection
    {
        return $this->requestAndResponse('SUBSCRIBE', [$this->escapeString($folder)], false);
    }

    /**
     * {@inheritDoc}
     */
    public function unsubscribeFolder(string $folder): ResponseCollection
    {
        return $this->requestAndResponse('UNSUBSCRIBE', [$this->escapeString($folder)], false);
    }

    /**
     * {@inheritDoc}
     */
    public function folders(string $reference = '', string $folder = '*'): ResponseCollection
    {
        $this->send('LIST', $this->escapeString($reference, $folder), $tag);

        $this->assertTaggedResponse($tag, fn () => throw new RuntimeException('Failed to fetch folders'));

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

        return $this->requestAndResponse('APPEND', $tokens, false);
    }

    /**
     * {@inheritDoc}
     */
    public function copyMessage(string $folder, $from, ?int $to = null): ResponseCollection
    {
        return $this->requestAndResponse('UID COPY', [
            $this->buildSet($from, $to),
            $this->escapeString($folder),
        ], false);
    }

    /**
     * {@inheritDoc}
     */
    public function copyManyMessages(array $messages, string $folder): ResponseCollection
    {
        $set = implode(',', $messages);

        $tokens = [$set, $this->escapeString($folder)];

        return $this->requestAndResponse('UID COPY', $tokens, false);
    }

    /**
     * {@inheritDoc}
     */
    public function moveMessage(string $folder, $from, ?int $to = null): ResponseCollection
    {
        $set = $this->buildSet($from, $to);

        return $this->requestAndResponse('UID MOVE', [$set, $this->escapeString($folder)], false);
    }

    /**
     * {@inheritDoc}
     */
    public function moveManyMessages(array $messages, string $folder): ResponseCollection
    {
        $set = implode(',', $messages);

        $tokens = [$set, $this->escapeString($folder)];

        return $this->requestAndResponse('UID MOVE', $tokens, false);
    }

    /**
     * {@inheritDoc}
     */
    public function store(array|string $flags, int $from, ?int $to = null, ?string $mode = null, bool $silent = true, ?string $item = null): ResponseCollection
    {
        $flags = $this->escapeList(Arr::wrap($flags));

        $set = $this->buildSet($from, $to);

        $item = ($mode == '-' ? '-' : '+').(is_null($item) ? 'FLAGS' : $item).($silent ? '.SILENT' : '');

        $response = $this->requestAndResponse('UID STORE', [$set, $item, $flags], ! $silent);

        if ($silent) {
            return $response;
        }

        $result = [];

        foreach ($response->data() as $token) {
            if ($token[1] != 'FETCH' || $token[2][0] != 'FLAGS') {
                continue;
            }

            $result[$token[0]] = $token[2][1];
        }

        return $response->setResult($result);
    }

    /**
     * {@inheritDoc}
     */
    public function uids(int|array $msgns): ResponseCollection
    {
        return $this->fetch(['UID'], Arr::wrap($msgns), null, Imap::ST_MSGN);
    }

    /**
     * {@inheritDoc}
     */
    public function contents(int|array $ids): ResponseCollection
    {
        return $this->fetch(['BODY[TEXT]'], Arr::wrap($ids));
    }

    /**
     * {@inheritDoc}
     */
    public function headers(int|array $ids): ResponseCollection
    {
        return $this->fetch(['BODY[HEADER]'], Arr::wrap($ids));
    }

    /**
     * {@inheritDoc}
     */
    public function flags(int|array $ids): ResponseCollection
    {
        return $this->fetch(['FLAGS'], Arr::wrap($ids));
    }

    /**
     * {@inheritDoc}
     */
    public function sizes(int|array $ids): ResponseCollection
    {
        return $this->fetch(['RFC822.SIZE'], Arr::wrap($ids));
    }

    /**
     * {@inheritDoc}
     */
    public function search(array $params): ResponseCollection
    {
        $response = $this->requestAndResponse('UID SEARCH', $params);

        $response->setCanBeEmpty(true);

        foreach ($response->data() as $ids) {
            if ($ids[0] === 'SEARCH') {
                array_shift($ids);

                return $response->setResult($ids);
            }
        }

        return $response;
    }

    /**
     * {@inheritDoc}
     */
    public function capability(): ResponseCollection
    {
        $response = $this->requestAndResponse('CAPABILITY');

        if (! $response->getResponse()) {
            return $response;
        }

        return $response->setResult(
            $response->getValidatedData()[0]
        );
    }

    /**
     * {@inheritDoc}
     */
    public function id(?array $ids = null): ResponseCollection
    {
        $token = 'NIL';

        if (is_array($ids) && ! empty($ids)) {
            $token = '(';

            foreach ($ids as $id) {
                $token .= '"'.$id.'" ';
            }

            $token = rtrim($token).')';
        }

        return $this->requestAndResponse('ID', [$token], false);
    }

    /**
     * {@inheritDoc}
     */
    public function expunge(): ResponseCollection
    {
        return $this->requestAndResponse('EXPUNGE');
    }

    /**
     * {@inheritDoc}
     */
    public function noop(): ResponseCollection
    {
        return $this->requestAndResponse('NOOP');
    }

    /**
     * {@inheritDoc}
     */
    public function idle(): void
    {
        $response = $this->send('IDLE');

        while (true) {
            $line = $this->nextReply($response);

            // Server indicates it's ready for IDLE.
            if (str_starts_with($line, '+ ')) {
                return;
            }

            // Typical untagged or tagged "OK" lines.
            if (preg_match('/^\* /i', $line) || preg_match('/^TAG\d+ OK/i', $line)) {
                continue;
            }

            // Unexpected response.
            throw new RuntimeException('Idle failed. Unexpected response: '.trim($line));
        }
    }

    /**
     * {@inheritDoc}
     */
    public function done(): void
    {
        $response = new ResponseCollection($this->sequence, $this->debug);

        $this->write($response, 'DONE');

        while (true) {
            $line = $this->nextReply($response);

            // Typical tagged "OK" line.
            if (preg_match('/^TAG\d+ OK/i', $line)) {
                break;
            }

            // Handle untagged notifications (e.g. "* 4 EXISTS").
            if (preg_match('/^\* /i', $line)) {
                continue;
            }

            // Unexpected response.
            throw new RuntimeException('Done failed. Unexpected response: '.trim($line));
        }
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
