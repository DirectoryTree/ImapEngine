<?php

namespace DirectoryTree\ImapEngine\Connection;

use DirectoryTree\ImapEngine\Exceptions\ConnectionClosedException;
use DirectoryTree\ImapEngine\Exceptions\ConnectionFailedException;
use DirectoryTree\ImapEngine\Exceptions\ConnectionTimedOutException;
use DirectoryTree\ImapEngine\Exceptions\ImapBadRequestException;
use DirectoryTree\ImapEngine\Exceptions\ImapServerErrorException;
use DirectoryTree\ImapEngine\Exceptions\RuntimeException;
use DirectoryTree\ImapEngine\Imap;
use DirectoryTree\ImapEngine\Support\Str;
use Exception;
use Illuminate\Support\Arr;

abstract class Connection implements ConnectionInterface
{
    /**
     * The underlying stream.
     */
    protected StreamInterface $stream;

    /**
     * Whether to debugging is enabled.
     */
    protected bool $debug = false;

    /**
     * Connection encryption method.
     */
    protected ?string $encryption = null;

    /**
     * Default connection timeout in seconds.
     */
    protected int $connectionTimeout = 30;

    /**
     * Whether certificate validation is enabled.
     */
    protected bool $certValidation = true;

    /**
     * The connection proxy settings.
     */
    protected array $proxy = [
        'socket' => null,
        'request_fulluri' => false,
        'username' => null,
        'password' => null,
    ];

    /**
     * Constructor.
     */
    public function __construct(StreamInterface $stream = new ImapStream)
    {
        $this->stream = $stream;
    }

    /**
     * Tear down the connection.
     */
    public function __destruct()
    {
        $this->logout();
    }

    /**
     * Reset the current stream and uid cache.
     */
    public function reset(): void
    {
        $this->stream->close();
    }

    /**
     * Check if the current stream is open.
     */
    public function connected(): bool
    {
        return $this->stream->isOpen();
    }

    /**
     * Get metadata about the current stream.
     */
    public function meta(): array
    {
        if ($this->stream->isOpen()) {
            return $this->stream->meta();
        }

        return [
            'crypto' => [
                'protocol' => '',
                'cipher_name' => '',
                'cipher_bits' => 0,
                'cipher_version' => '',
            ],
            'timed_out' => true,
            'blocked' => true,
            'eof' => true,
            'stream_type' => 'tcp_socket/unknown',
            'mode' => 'c',
            'unread_bytes' => 0,
            'seekable' => false,
        ];
    }

    /**
     * Set the encryption method.
     */
    public function setEncryption(string $encryption): void
    {
        $this->encryption = $encryption;
    }

    /**
     * Get the encryption method.
     */
    public function getEncryption(): ?string
    {
        return $this->encryption;
    }

    /**
     * Set SSL certificate validation.
     */
    public function setCertValidation(int $certValidation): Connection
    {
        $this->certValidation = $certValidation;

        return $this;
    }

    /**
     * Should we validate SSL certificate?
     */
    public function getCertValidation(): bool
    {
        return $this->certValidation;
    }

    /**
     * Set connection proxy settings.
     */
    public function setProxy(array $options): Connection
    {
        foreach ($this->proxy as $key => $val) {
            if (isset($options[$key])) {
                $this->proxy[$key] = $options[$key];
            }
        }

        return $this;
    }

    /**
     * Get the current proxy settings.
     */
    public function getProxy(): array
    {
        return $this->proxy;
    }

    /**
     * Enable or disable debug mode.
     */
    public function setDebug(bool $enabled): void
    {
        $this->debug = $enabled;
    }

    /**
     * Get the current connection timeout.
     */
    public function getConnectionTimeout(): int
    {
        return $this->connectionTimeout;
    }

    /**
     * Set the connection timeout.
     */
    public function setConnectionTimeout(int $connectionTimeout): Connection
    {
        $this->connectionTimeout = $connectionTimeout;

        return $this;
    }

    /**
     * Set the stream timeout.
     */
    public function setStreamTimeout(int $streamTimeout): Connection
    {
        if (! $this->stream->setTimeout($streamTimeout)) {
            throw new ConnectionFailedException('Failed to set stream timeout');
        }

        return $this;
    }

    /**
     * Get an available cryptographic method.
     */
    public function getCryptoMethod(): int
    {
        // Allow the best TLS version(s) we can.
        $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT;

        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            $cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        } elseif (defined('STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT')) {
            $cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT;
        }

        return $cryptoMethod;
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
     * Get the default socket options.
     */
    protected function getDefaultSocketOptions(string $transport): array
    {
        $options = [];

        if ($this->encryption) {
            $options['ssl'] = [
                'verify_peer_name' => $this->getCertValidation(),
                'verify_peer' => $this->getCertValidation(),
            ];
        }

        if ($this->proxy['socket']) {
            $options[$transport]['proxy'] = $this->proxy['socket'];
            $options[$transport]['request_fulluri'] = $this->proxy['request_fulluri'];

            if ($this->proxy['username'] != null) {
                $auth = base64_encode($this->proxy['username'].':'.$this->proxy['password']);

                $options[$transport]['header'] = [
                    "Proxy-Authorization: Basic $auth",
                ];
            }
        }

        return $options;
    }

    /**
     * Connect to a host.
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
                $this->getDefaultSocketOptions($transport),
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
     * Write data to the current stream.
     */
    protected function write(Response $response, string $data): void
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
     * Send a new IMAP request.
     *
     * @param  array  $tokens  additional parameters to command, use escapeString() to prepare
     * @param  string|null  $tag  provide a tag otherwise an autogenerated is returned
     */
    public function sendCommand(string $command, array $tokens = [], ?string &$tag = null): Response
    {
        $command = new ImapCommand($command, $tokens);

        if (! $tag) {
            $this->sequence++;

            $tag = 'TAG'.$this->sequence;
        }

        $command->setTag($tag);

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
            throw new RuntimeException('Failed to send literal string.');
        }

        return $response;
    }

    /**
     * Send a request and get response at once.
     *
     * @param  bool  $parse  if true, parse the response lines into tokens; if false, return raw lines
     */
    public function requestAndResponse(string $command, array $tokens = [], bool $parse = true): Response
    {
        $response = $this->sendCommand($command, $tokens, $tag);

        $response->setResult(
            $this->readResponse($response, $tag, $parse)
        );

        return $response;
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
            $tokens = $this->tokenize($line);
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
     * Parse the line into tokens.
     */
    protected function tokenize(string $line): array
    {
        return $this->flattenTokens(
            (new ImapStreamTokenizer)->tokenize($this->stream, $line)
        );
    }

    /**
     * Fetch one or more items of one or more messages.
     */
    protected function fetch(array|string $items, array|int $from, mixed $to = null, $identifier = Imap::ST_UID): Response
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

        $prefix = match ($identifier) {
            Imap::ST_UID => 'UID',
            default => '',
        };

        $response = $this->sendCommand(
            trim($prefix.' FETCH'),
            [$set, $this->escapeList($items)],
            $tag
        );

        $result = [];
        $tokens = [];

        while (! $this->readLine($response, $tokens, $tag)) {
            if (! isset($tokens[1])) {
                continue;
            }

            // Ignore other responses.
            if ($tokens[1] != 'FETCH') {
                continue;
            }

            $uidKey = 0;
            $data = [];

            // Find array key of UID value; try the last elements, or search for it.
            if ($identifier === Imap::ST_UID) {
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
            if (is_null($to) && ! is_array($from) && ($identifier === Imap::ST_UID ? $tokens[2][$uidKey] != $from : $tokens[0] != $from)) {
                continue;
            }

            // If we only want one item we return that one directly.
            if (count($items) == 1) {
                if ($tokens[2][0] == $items[0]) {
                    $data = $tokens[2][1];
                } elseif ($identifier === Imap::ST_UID && $tokens[2][2] == $items[0]) {
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
            if (is_null($to) && ! is_array($from) && ($identifier === Imap::ST_UID ? $tokens[2][$uidKey] == $from : $tokens[0] == $from)) {
                // We still need to read all lines.
                if (! $this->readLine($response, $tokens, $tag)) {
                    return $response->setResult($data);
                }
            }

            if ($identifier === Imap::ST_UID) {
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
     * Escape one or more literals.
     */
    protected function escapeString(array|string ...$string): array|string
    {
        return Str::literal(...$string);
    }

    /**
     * Escape a list with literals or lists.
     */
    protected function escapeList(array $list): string
    {
        return Str::list($list);
    }
}
