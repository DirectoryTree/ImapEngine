<?php

namespace DirectoryTree\ImapEngine\Connection;

use DirectoryTree\ImapEngine\Exceptions\AuthFailedException;
use DirectoryTree\ImapEngine\Exceptions\ConnectionClosedException;
use DirectoryTree\ImapEngine\Exceptions\ConnectionFailedException;
use DirectoryTree\ImapEngine\Exceptions\ConnectionTimedOutException;
use DirectoryTree\ImapEngine\Exceptions\ImapBadRequestException;
use DirectoryTree\ImapEngine\Exceptions\ImapServerErrorException;
use DirectoryTree\ImapEngine\Exceptions\RuntimeException;
use DirectoryTree\ImapEngine\Imap;
use DirectoryTree\ImapEngine\Support\Escape;
use Exception;
use Illuminate\Support\Arr;
use Throwable;

/**
 * @see https://www.rfc-editor.org/rfc/rfc2087.txt
 */
class ImapConnection extends Connection
{
    /**
     * The current request sequence.
     */
    protected int $sequence = 0;

    /**
     * Tear down the connection.
     */
    public function __destruct()
    {
        $this->logout();
    }

    /**
     * {@inheritDoc}
     */
    public function connect(string $host, ?int $port = null): void
    {
        $transport = 'tcp';
        $encryption = '';

        if ($this->encryption) {
            $encryption = strtolower($this->encryption);

            if (in_array($encryption, ['ssl', 'tls'])) {
                $transport = $encryption;
                $port ??= 993;
            }
        }

        $port ??= 143;

        try {
            $response = new Response(0, $this->debug);

            $this->stream->open(
                $transport,
                $host,
                $port,
                $this->connectionTimeout,
                $this->defaultSocketOptions($transport),
            );

            // Upon opening the connection, we should receive
            // an initial IMAP greeting message from the
            // server to indicate it was successful.
            if (! $this->assumedNextLine($response, '* OK')) {
                throw new ConnectionFailedException('Connection refused');
            }

            $this->setStreamTimeout($this->connectionTimeout);

            if ($encryption == 'starttls') {
                $this->enableStartTls();
            }
        } catch (Exception $e) {
            throw new ConnectionFailedException('Connection failed', 0, $e);
        }
    }

    /**
     * Enable TLS on the current connection.
     */
    protected function enableStartTls(): void
    {
        $response = $this->requestAndResponse('STARTTLS');

        $result = $response->successful() && $this->stream->setSocketSetCrypto(true, $this->getCryptoMethod());

        if (! $result) {
            throw new ConnectionFailedException('Failed to enable TLS');
        }
    }

    /**
     * Get the next line from stream.
     */
    public function nextLine(Response $response): string
    {
        $line = $this->stream->fgets();

        if ($line === false) {
            $meta = $this->meta();

            throw match (true) {
                $meta['timed_out'] ?? false => new ConnectionTimedOutException('Stream timed out, no response'),
                $meta['eof'] ?? false => new ConnectionClosedException('Server closed the connection (EOF)'),
                default => new RuntimeException('Unknown read error, no response: '.json_encode($meta)),
            };
        }

        $response->push($line);

        if ($this->debug) {
            echo '<< '.$line;
        }

        return $line;
    }

    /**
     * Get the next tagged line along with the containing tag.
     */
    protected function nextTaggedLine(Response $response, ?string &$tag): string
    {
        $line = $this->nextLine($response);

        if (str_contains($line, ' ')) {
            [$tag, $line] = explode(' ', $line, 2);
        }

        return $line ?? '';
    }

    /**
     * Get the next line and check if it starts with a given string.
     */
    protected function assumedNextLine(Response $response, string $start): bool
    {
        return str_starts_with($this->nextLine($response), $start);
    }

    /**
     * Get the next line and check if it contains a given string and split the tag.
     */
    protected function assumedNextTaggedLine(Response $response, string $start, ?string &$tag): bool
    {
        return str_contains($this->nextTaggedLine($response, $tag), $start);
    }

    /**
     * Split a given line in values. A value is a literal of any form or a list.
     */
    protected function decodeLine(Response $response, string $line): array
    {
        $tokens = [];
        $stack = [];

        // Replace any trailing <NL> including spaces with a single space.
        $line = rtrim($line).' ';

        while (($pos = strpos($line, ' ')) !== false) {
            $token = substr($line, 0, $pos);

            if (! strlen($token)) {
                $line = substr($line, $pos + 1);

                continue;
            }

            // Handle opening parentheses by pushing current tokens to stack.
            while ($token[0] == '(') {
                $stack[] = $tokens;

                $tokens = [];

                $token = substr($token, 1);
            }

            if ($token[0] == '"') {
                if (preg_match('%^\(*\"((.|\\\|\")*?)\"( |$)%', $line, $matches)) {
                    $tokens[] = $matches[1];

                    $line = substr($line, strlen($matches[0]));

                    continue;
                }
            }

            if ($token[0] == '{') {
                // Extract the byte count from the literal (e.g., {20}).
                $endPos = strpos($token, '}');
                $chars = substr($token, 1, $endPos - 1);

                if (is_numeric($chars)) {
                    $token = '';

                    // Read exactly the number of bytes specified by the literal.
                    while (strlen($token) < $chars) {
                        $token .= $this->nextLine($response);
                    }

                    $line = '';

                    // If more bytes are read than required, split the excess.
                    if (strlen($token) > $chars) {
                        $line = substr($token, $chars);
                        $token = substr($token, 0, $chars);
                    } else {
                        // Continue reading the next line if exact bytes are read.
                        $line .= $this->nextLine($response);
                    }

                    // Add the exact literal data to the tokens array.
                    $tokens[] = $token;

                    // Trim any trailing spaces for further processing.
                    $line = trim($line).' ';

                    continue;
                }
            }

            // Handle closing parentheses and manage stack.
            if ($stack && $token[strlen($token) - 1] == ')') {
                // Closing braces are not separated by spaces, so we need to count them.
                $braces = strlen($token);

                $token = rtrim($token, ')');

                // Only count braces if more than one.
                $braces -= strlen($token) + 1;

                // Only add if token had more than just closing braces.
                if (rtrim($token) != '') {
                    $tokens[] = rtrim($token);
                }

                $token = $tokens;

                $tokens = array_pop($stack);

                // Special handling if more than one closing brace.
                while ($braces-- > 0) {
                    $tokens[] = $token;
                    $token = $tokens;
                    $tokens = array_pop($stack);
                }
            }

            // Add the current token to the tokens array
            $tokens[] = $token;

            // Move to the next part of the line.
            $line = substr($line, $pos + 1);
        }

        // Maybe the server forgot to send some closing braces.
        while ($stack) {
            $child = $tokens;

            $tokens = array_pop($stack);

            $tokens[] = $child;
        }

        return $tokens;
    }

    /**
     * Read and optionally parse a response "line".
     *
     * @param  array|string  $tokens  to decode
     * @param  string  $wantedTag  targeted tag
     * @param  bool  $parse  if true, line is decoded into tokens; if false, the unparsed line is returned
     */
    public function readLine(Response $response, array|string &$tokens = [], string $wantedTag = '*', bool $parse = true): bool
    {
        $line = $this->nextTaggedLine($response, $tag); // Get next tag.

        if ($parse) {
            $tokens = $this->decodeLine($response, $line);
        } else {
            $tokens = $line;
        }

        // If tag is wanted tag we might be at the end of a multiline response.
        return $tag == $wantedTag;
    }

    /**
     * Read all lines of response until given tag is found.
     *
     * @param  string  $tag  request tag
     * @param  bool  $parse  if true, lines are decoded; if false, lines are returned raw
     */
    public function readResponse(Response $response, string $tag, bool $parse = true): array
    {
        $lines = [];
        $tokens = '';

        do {
            $readAll = $this->readLine($response, $tokens, $tag, $parse);
            $lines[] = $tokens;
        } while (! $readAll);

        $original = $tokens;

        if (! $parse) {
            // First two chars are still needed for the response code.
            $tokens = [trim(substr($tokens, 0, 3))];
        }

        $original = Arr::wrap($original);

        // Last line has response code.
        if ($tokens[0] == 'OK') {
            return $lines ?: [true];
        }

        if (in_array($tokens[0], ['NO', 'BAD', 'BYE'])) {
            throw ImapServerErrorException::fromResponseTokens($original);
        }

        throw ImapBadRequestException::fromResponseTokens($original);
    }

    /**
     * Send a new request.
     *
     * @param  array  $tokens  additional parameters to command, use escapeString() to prepare
     * @param  string|null  $tag  provide a tag otherwise an autogenerated is returned
     */
    public function sendRequest(string $command, array $tokens = [], ?string &$tag = null): Response
    {
        $imapCommand = new ImapCommand($command, $tokens);

        if (! $tag) {
            $this->sequence++;

            $tag = 'TAG'.$this->sequence;
        }

        $imapCommand->setTag($tag);

        return $this->sendCommand($imapCommand);
    }

    /**
     * Dispatch the given IMAP command.
     */
    protected function sendCommand(ImapCommand $command): Response
    {
        if (! $command->getTag()) {
            $this->sequence++;

            $command->setTag('TAG'.$this->sequence);
        }

        $response = new Response($this->sequence, $this->debug);

        foreach ($command->compile() as $line) {
            $this->write($response, $line);

            // If the line doesn't end with a literal marker, move on.
            if (! str_ends_with($line, '}')) {
                continue;
            }

            // If the line does end with a literal marker, check for the expected continuation.
            if ($this->assumedNextLine($response, '+ ')) {
                continue;
            }

            // Early return: if we didn't get the continuation, throw immediately.
            throw new RuntimeException('Failed to send literal string');
        }

        return $response;
    }

    /**
     * Write data to the current stream.
     */
    public function write(Response $response, string $data): void
    {
        $command = $data."\r\n";

        if ($this->debug) {
            echo '>> '.$command."\n";
        }

        $response->addCommand($command);

        if ($this->stream->fwrite($command) === false) {
            throw new RuntimeException('Failed to write - connection closed?');
        }
    }

    /**
     * Send a request and get response at once.
     *
     * @param  bool  $parse  if true, parse the response lines into tokens; if false, return raw lines
     */
    public function requestAndResponse(string $command, array $tokens = [], bool $parse = true): Response
    {
        $response = $this->sendRequest($command, $tokens, $tag);

        $response->setResult(
            $this->readResponse($response, $tag, $parse)
        );

        return $response;
    }

    /**
     * {@inheritDoc}
     */
    public function login(string $user, string $password): Response
    {
        try {
            return $this->requestAndResponse('LOGIN', $this->escapeString($user, $password), false);
        } catch (RuntimeException $e) {
            throw new AuthFailedException('Failed to authenticate', 0, $e);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function authenticate(string $user, string $token): Response
    {
        try {
            $authenticateParams = ['XOAUTH2', base64_encode("user=$user\1auth=Bearer $token\1\1")];

            $response = $this->sendRequest('AUTHENTICATE', $authenticateParams);

            while (true) {
                $tokens = '';

                if ($this->readLine($response, $tokens, '+', false)) {
                    $response->addResponse($this->sendRequest(''));

                    continue;
                }

                if (preg_match('/^(NO|BAD) /i', $tokens)) {
                    return $response->addError("got failure response: $tokens");
                }

                if (preg_match('/^OK /i', $tokens)) {
                    return $response->setResult(is_array($tokens) ? $tokens : [$tokens]);
                }
            }
        } catch (RuntimeException $e) {
            throw new AuthFailedException('Failed to authenticate', 0, $e);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function logout(): Response
    {
        if (! $this->stream->isOpen()) {
            $this->reset();

            return new Response(0, $this->debug);
        } elseif ($this->meta()['timed_out']) {
            $this->reset();

            return new Response(0, $this->debug);
        }

        $result = null;

        try {
            $result = $this->requestAndResponse('LOGOUT', [], false);

            $this->stream->close();
        } catch (Throwable) {
            // Do nothing.
        }

        $this->reset();

        return $result ?? new Response(0, $this->debug);
    }

    /**
     * Reset the current stream and uid cache.
     */
    public function reset(): void
    {
        $this->stream->close();
    }

    /**
     * Examine and select have the same response.
     *
     * @param  string  $command  can be 'EXAMINE' or 'SELECT'
     * @param  string  $folder  target folder
     */
    public function examineOrSelect(string $command = 'EXAMINE', string $folder = 'INBOX'): Response
    {
        $response = $this->sendRequest($command, [$this->escapeString($folder)], $tag);

        $result = [];
        $tokens = [];

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
    public function selectFolder(string $folder = 'INBOX'): Response
    {
        return $this->examineOrSelect('SELECT', $folder);
    }

    /**
     * {@inheritDoc}
     */
    public function examineFolder(string $folder = 'INBOX'): Response
    {
        return $this->examineOrSelect('EXAMINE', $folder);
    }

    /**
     * {@inheritDoc}
     */
    public function folderStatus(string $folder = 'INBOX', array $arguments = ['MESSAGES', 'UNSEEN', 'RECENT', 'UIDNEXT', 'UIDVALIDITY']): Response
    {
        $response = $this->requestAndResponse('STATUS', [
            $this->escapeString($folder),
            $this->escapeList($arguments),
        ]);

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
     * Fetch one or more items of one or more messages.
     */
    public function fetch(array|string $items, array|int $from, mixed $to = null, int|string $uid = Imap::ST_UID): Response
    {
        if (is_array($from) && count($from) > 1) {
            $set = implode(',', $from);
        } elseif (is_array($from) && count($from) === 1) {
            $set = $from[0].':'.$from[0];
        } elseif (is_null($to)) {
            $set = $from.':'.$from;
        } elseif ($to == INF) {
            $set = $from.':*';
        } else {
            $set = $from.':'.(int) $to;
        }

        $items = (array) $items;

        $response = $this->sendRequest(
            $this->buildUidCommand('FETCH', $uid),
            [$set, $this->escapeList($items)],
            $tag
        );

        $result = [];
        $tokens = [];

        while (! $this->readLine($response, $tokens, $tag)) {
            // Ignore other responses.
            if ($tokens[1] != 'FETCH') {
                continue;
            }

            $uidKey = 0;
            $data = [];

            // Find array key of UID value; try the last elements, or search for it.
            if ($uid === Imap::ST_UID) {
                $count = count($tokens[2]);

                if ($tokens[2][$count - 2] == 'UID') {
                    $uidKey = $count - 1;
                } elseif ($tokens[2][0] == 'UID') {
                    $uidKey = 1;
                } else {
                    $found = array_search('UID', $tokens[2]);

                    if ($found === false || $found === -1) {
                        continue;
                    }

                    $uidKey = $found + 1;
                }
            }

            // Ignore other messages.
            if (is_null($to) && ! is_array($from) && ($uid === Imap::ST_UID ? $tokens[2][$uidKey] != $from : $tokens[0] != $from)) {
                continue;
            }

            // If we only want one item we return that one directly.
            if (count($items) == 1) {
                if ($tokens[2][0] == $items[0]) {
                    $data = $tokens[2][1];
                } elseif ($uid === Imap::ST_UID && $tokens[2][2] == $items[0]) {
                    $data = $tokens[2][3];
                } else {
                    $expectedResponse = 0;

                    // Maybe the server send another field we didn't wanted.
                    $count = count($tokens[2]);

                    // We start with 2, because 0 was already checked.
                    for ($i = 2; $i < $count; $i += 2) {
                        if ($tokens[2][$i] != $items[0]) {
                            continue;
                        }

                        $data = $tokens[2][$i + 1];

                        $expectedResponse = 1;

                        break;
                    }

                    if (! $expectedResponse) {
                        continue;
                    }
                }
            } else {
                while (key($tokens[2]) !== null) {
                    $data[current($tokens[2])] = next($tokens[2]);

                    next($tokens[2]);
                }
            }

            // If we want only one message we can ignore everything else and just return.
            if (is_null($to) && ! is_array($from) && ($uid === Imap::ST_UID ? $tokens[2][$uidKey] == $from : $tokens[0] == $from)) {
                // We still need to read all lines.
                if (! $this->readLine($response, $tokens, $tag)) {
                    return $response->setResult($data);
                }
            }

            if ($uid === Imap::ST_UID) {
                $result[$tokens[2][$uidKey]] = $data;
            } else {
                $result[$tokens[0]] = $data;
            }
        }

        if (is_null($to) && ! is_array($from)) {
            throw new RuntimeException('The single id was not found in response');
        }

        return $response->setResult($result);
    }

    /**
     * {@inheritDoc}
     */
    public function content(int|array $uids, string $rfc = 'RFC822', int|string $uid = Imap::ST_UID): Response
    {
        return $this->fetch(["$rfc.TEXT"], Arr::wrap($uids), null, $uid);
    }

    /**
     * {@inheritDoc}
     */
    public function headers(int|array $uids, string $rfc = 'RFC822', int|string $uid = Imap::ST_UID): Response
    {
        return $this->fetch(["$rfc.HEADER"], Arr::wrap($uids), null, $uid);
    }

    /**
     * {@inheritDoc}
     */
    public function flags(int|array $uids, int|string $uid = Imap::ST_UID): Response
    {
        return $this->fetch(['FLAGS'], Arr::wrap($uids), null, $uid);
    }

    /**
     * {@inheritDoc}
     */
    public function sizes(int|array $uids, int|string $uid = Imap::ST_UID): Response
    {
        return $this->fetch(['RFC822.SIZE'], Arr::wrap($uids), null, $uid);
    }

    /**
     * {@inheritDoc}
     */
    public function folders(string $reference = '', string $folder = '*'): Response
    {
        $response = $this->requestAndResponse('LIST', $this->escapeString($reference, $folder));

        $response->setCanBeEmpty(true);

        $list = $response->data();

        $result = [];

        if ($list[0] !== true) {
            foreach ($list as $item) {
                if (count($item) != 4 || $item[0] != 'LIST') {
                    continue;
                }

                $item[3] = str_replace('\\\\', '\\', str_replace('\\"', '"', $item[3]));

                $result[$item[3]] = [
                    'delimiter' => $item[2],
                    'flags' => $item[1],
                ];
            }
        }

        return $response->setResult($result);
    }

    /**
     * {@inheritDoc}
     */
    public function store(
        array|string $flags,
        int $from,
        ?int $to = null,
        ?string $mode = null,
        bool $silent = true,
        int|string $uid = Imap::ST_UID,
        ?string $item = null
    ): Response {
        $flags = $this->escapeList(Arr::wrap($flags));

        $set = $this->buildSet($from, $to);

        $command = $this->buildUidCommand('STORE', $uid);

        $item = ($mode == '-' ? '-' : '+').(is_null($item) ? 'FLAGS' : $item).($silent ? '.SILENT' : '');

        $response = $this->requestAndResponse($command, [$set, $item, $flags], ! $silent);

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
    public function appendMessage(string $folder, string $message, ?array $flags = null, ?string $date = null): Response
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
    public function copyMessage(string $folder, $from, ?int $to = null, int|string $uid = Imap::ST_UID): Response
    {
        $set = $this->buildSet($from, $to);

        $command = $this->buildUidCommand('COPY', $uid);

        return $this->requestAndResponse($command, [$set, $this->escapeString($folder)], false);
    }

    /**
     * {@inheritDoc}
     */
    public function copyManyMessages(array $messages, string $folder, int|string $uid = Imap::ST_UID): Response
    {
        $command = $this->buildUidCommand('COPY', $uid);

        $set = implode(',', $messages);

        $tokens = [$set, $this->escapeString($folder)];

        return $this->requestAndResponse($command, $tokens, false);
    }

    /**
     * {@inheritDoc}
     */
    public function moveMessage(string $folder, $from, ?int $to = null, int|string $uid = Imap::ST_UID): Response
    {
        $set = $this->buildSet($from, $to);

        $command = $this->buildUidCommand('MOVE', $uid);

        return $this->requestAndResponse($command, [$set, $this->escapeString($folder)], false);
    }

    /**
     * {@inheritDoc}
     */
    public function moveManyMessages(array $messages, string $folder, int|string $uid = Imap::ST_UID): Response
    {
        $command = $this->buildUidCommand('MOVE', $uid);

        $set = implode(',', $messages);

        $tokens = [$set, $this->escapeString($folder)];

        return $this->requestAndResponse($command, $tokens, false);
    }

    /**
     * {@inheritDoc}
     */
    public function id(?array $ids = null): Response
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
    public function createFolder(string $folder): Response
    {
        return $this->requestAndResponse('CREATE', [$this->escapeString($folder)], false);
    }

    /**
     * {@inheritDoc}
     */
    public function renameFolder(string $oldPath, string $newPath): Response
    {
        return $this->requestAndResponse('RENAME', $this->escapeString($oldPath, $newPath), false);
    }

    /**
     * {@inheritDoc}
     */
    public function deleteFolder(string $folder): Response
    {
        return $this->requestAndResponse('DELETE', [$this->escapeString($folder)], false);
    }

    /**
     * {@inheritDoc}
     */
    public function subscribeFolder(string $folder): Response
    {
        return $this->requestAndResponse('SUBSCRIBE', [$this->escapeString($folder)], false);
    }

    /**
     * {@inheritDoc}
     */
    public function unsubscribeFolder(string $folder): Response
    {
        return $this->requestAndResponse('UNSUBSCRIBE', [$this->escapeString($folder)], false);
    }

    /**
     * {@inheritDoc}
     */
    public function expunge(): Response
    {
        return $this->requestAndResponse('EXPUNGE');
    }

    /**
     * {@inheritDoc}
     */
    public function noop(): Response
    {
        return $this->requestAndResponse('NOOP');
    }

    /**
     * {@inheritDoc}
     */
    public function idle(): void
    {
        $response = $this->sendRequest('IDLE');

        while (true) {
            $line = $this->nextLine($response);

            if (str_starts_with($line, '+ ')) {
                return;
            }

            if (preg_match('/^\* OK/i', $line) || preg_match('/^TAG\d+ OK/i', $line)) {
                continue;
            }

            throw new RuntimeException('Idle failed. Unexpected response: '.trim($line));
        }
    }

    /**
     * {@inheritDoc}
     */
    public function done(): void
    {
        $response = new Response($this->sequence, $this->debug);

        $this->write($response, 'DONE');

        if (! $this->assumedNextTaggedLine($response, 'OK', $tags)) {
            throw new RuntimeException('Done failed');
        }
    }

    /**
     * {@inheritDoc}
     */
    public function search(array $params, int|string $uid = Imap::ST_UID): Response
    {
        $command = $this->buildUidCommand('SEARCH', $uid);

        $response = $this->requestAndResponse($command, $params);

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
    public function capability(): Response
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
    public function getQuota(string $username): Response
    {
        return $this->requestAndResponse('GETQUOTA', ['"#user/'.$username.'"']);
    }

    /**
     * {@inheritDoc}
     */
    public function getQuotaRoot(string $quotaRoot = 'INBOX'): Response
    {
        return $this->requestAndResponse('GETQUOTAROOT', [$quotaRoot]);
    }

    /**
     * Enable the debug mode.
     */
    public function setDebug(bool $enabled): void
    {
        $this->debug = $enabled;
    }

    /**
     * Disable the debug mode.
     */
    public function disableDebug(): void
    {
        $this->debug = false;
    }

    /**
     * Build a valid UID number set.
     */
    public function buildSet($from, $to = null): int|string
    {
        $set = (int) $from;

        if ($to !== null) {
            $set .= ':'.($to == INF ? '*' : (int) $to);
        }

        return $set;
    }

    /**
     * Escape one or more literals i.e. for sendRequest.
     */
    protected function escapeString(array|string ...$string): array|string
    {
        return Escape::string(...$string);
    }

    /**
     * Escape a list with literals or lists.
     */
    protected function escapeList(array $list): string
    {
        return Escape::list($list);
    }
}
