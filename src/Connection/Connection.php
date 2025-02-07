<?php

namespace DirectoryTree\ImapEngine\Connection;

use DirectoryTree\ImapEngine\Connection\Responses\Response;
use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
use DirectoryTree\ImapEngine\Exceptions\ConnectionClosedException;
use DirectoryTree\ImapEngine\Exceptions\ConnectionFailedException;
use DirectoryTree\ImapEngine\Exceptions\ConnectionTimedOutException;
use DirectoryTree\ImapEngine\Exceptions\RuntimeException;
use DirectoryTree\ImapEngine\Imap;
use DirectoryTree\ImapEngine\Support\Str;

abstract class Connection implements ConnectionInterface
{
    use ParsesResponses;

    /**
     * The underlying stream.
     */
    protected StreamInterface $stream;

    /**
     * Sequence number used to generate unique command tags.
     */
    protected int $sequence = 0;

    /**
     * Whether debugging is enabled.
     */
    protected bool $debug = false;

    /**
     * Connection encryption method.
     */
    protected ?string $encryption = null;

    /**
     * Whether certificate validation is enabled.
     */
    protected bool $certValidation = true;

    /**
     * Default connection timeout in seconds.
     */
    protected int $connectionTimeout = 30;

    /**
     * The connection proxy settings.
     */
    protected array $proxy = [
        'socket' => null,
        'username' => null,
        'password' => null,
        'request_fulluri' => false,
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
        //        $this->logout();
        //        $this->close();
    }

    /**
     * Close the current stream.
     */
    public function close(): void
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

        // Return dummy metadata when stream is closed.
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
     * Set the connection encryption method.
     */
    public function setEncryption(string $encryption): void
    {
        $this->encryption = $encryption;
    }

    /**
     * Get the connection encryption method.
     */
    public function getEncryption(): ?string
    {
        return $this->encryption;
    }

    /**
     * Set whether certificate validation is enabled.
     */
    public function setCertValidation(int $certValidation): Connection
    {
        $this->certValidation = $certValidation;

        return $this;
    }

    /**
     * Get whether certificate validation is enabled.
     */
    public function getCertValidation(): bool
    {
        return $this->certValidation;
    }

    /**
     * Set the connection proxy settings.
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
     * Get the connection proxy settings.
     */
    public function getProxy(): array
    {
        return $this->proxy;
    }

    /**
     * Enable or disable debugging.
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
     * Get the best available crypto method.
     */
    public function getCryptoMethod(): int
    {
        // Use the best available TLS client method.
        $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            $cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        } elseif (defined('STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT')) {
            $cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT;
        }

        return $cryptoMethod;
    }

    /**
     * Enable STARTTLS on the current connection.
     */
    protected function enableStartTls(): void
    {
        $response = $this->send('STARTTLS');

        $result = $response->successful() && $this->stream->setSocketSetCrypto(true, $this->getCryptoMethod());

        if (! $result) {
            throw new ConnectionFailedException('Failed to enable TLS');
        }
    }

    /**
     * Get the default socket options for the given transport.
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
                $options[$transport]['header'] = ["Proxy-Authorization: Basic $auth"];
            }
        }

        return $options;
    }

    /**
     * {@inheritDoc}
     */
    public function connect(string $host, ?int $port = null): void
    {
        $transport = $this->getTransport();

        if (in_array($transport, ['ssl', 'tls'])) {
            $port ??= 993;
        } else {
            $port ??= 143;
        }

        $this->setParser(
            $this->newParser($this->stream)
        );

        $this->stream->open(
            $transport,
            $host,
            $port,
            $this->connectionTimeout,
            $this->getDefaultSocketOptions($transport)
        );

        $this->assertNextResponse(
            fn (Response $response) => $response instanceof UntaggedResponse,
            fn (UntaggedResponse $response) => $response->type()->is('OK'),
            fn () => new ConnectionFailedException("Connection to $host:$port failed")
        );

        $this->setStreamTimeout($this->connectionTimeout);

        if ($transport === 'starttls') {
            $this->enableStartTls();
        }
    }

    /**
     * Get the transport method for the current connection.
     */
    protected function getTransport(): string
    {
        return $this->encryption ? strtolower($this->encryption) : 'tcp';
    }

    /**
     * Read the next reply from the stream.
     */
    public function nextReply(): Response
    {
        if (! $reply = $this->parser->next()) {
            $meta = $this->meta();

            throw match (true) {
                $meta['timed_out'] ?? false => new ConnectionTimedOutException('Stream timed out, no response'),
                $meta['eof'] ?? false => new ConnectionClosedException('Server closed the connection (EOF)'),
                default => new RuntimeException('Unknown read error, no response: '.json_encode($meta)),
            };
        }

        if ($this->debug) {
            echo '<< '.$reply;
        }

        return $reply;
    }

    /**
     * Send an IMAP command.
     */
    public function send(string $name, array $tokens = [], ?string &$tag = null): void
    {
        $command = new ImapCommand($name, $tokens);

        if (! $tag) {
            $this->sequence++;
            $tag = 'TAG'.$this->sequence;
        }

        $command->setTag($tag);

        $result = new Result;

        $this->setResult($result);

        $result->addCommand($command);

        foreach ($command->compile() as $line) {
            $this->write($line);
        }
    }

    /**
     * Write data to the stream.
     */
    protected function write(string $data): void
    {
        $command = $data."\r\n";

        if ($this->debug) {
            echo '>> '.$command."\n";
        }

        if ($this->stream->fwrite($command) === false) {
            throw new RuntimeException('Failed to write data to stream.');
        }
    }

    /**
     * Fetch one or more items for one or more messages.
     *
     * This method compiles the FETCH command, sends it, and then uses the new parser
     * to obtain the parsed result.
     */
    public function fetch(array|string $items, array|int $from, mixed $to = null, $identifier = Imap::ST_UID): UntaggedResponse
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
        $prefix = ($identifier === Imap::ST_UID) ? 'UID' : '';

        $this->send(
            trim($prefix.' FETCH'),
            [$set, $this->escapeList($items)],
            $tag
        );

        return $this->assertUntaggedResponse(
            fn (UntaggedResponse $response) => $response->tokenAt(2)?->is('FETCH'),
            fn () => new RuntimeException('Failed to fetch items')
        );
    }

    /**
     * Escape one or more literal strings.
     */
    protected function escapeString(array|string ...$string): array|string
    {
        return Str::literal(...$string);
    }

    /**
     * Escape a list of literals.
     */
    protected function escapeList(array $list): string
    {
        return Str::list($list);
    }
}
