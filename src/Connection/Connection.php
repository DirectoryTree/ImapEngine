<?php

namespace DirectoryTree\ImapEngine\Connection;

use DirectoryTree\ImapEngine\Exceptions\ConnectionFailedException;
use DirectoryTree\ImapEngine\Imap;

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
     * The UID cache of active folder.
     */
    protected array $uidCache = [];

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
     * Get an available cryptographic method.
     */
    public function getCryptoMethod(): int
    {
        // Allow the best TLS version(s) we can
        $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT;

        // PHP 5.6.7 dropped inclusion of TLS 1.1 and 1.2 in STREAM_CRYPTO_METHOD_TLS_CLIENT
        // so add them back in manually if we can
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            $cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        } elseif (defined('STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT')) {
            $cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT;
        }

        return $cryptoMethod;
    }

    /**
     * Enable SSL certificate validation.
     */
    public function enableCertValidation(): Connection
    {
        $this->certValidation = true;

        return $this;
    }

    /**
     * Disable SSL certificate validation.
     */
    public function disableCertValidation(): Connection
    {
        $this->certValidation = false;

        return $this;
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
     * Prepare socket options.
     */
    protected function defaultSocketOptions(string $transport): array
    {
        $options = [];

        if ($this->encryption) {
            $options['ssl'] = [
                'verify_peer_name' => $this->getCertValidation(),
                'verify_peer' => $this->getCertValidation(),
            ];
        }

        if ($this->proxy['socket'] != null) {
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
     * Get the UID key string.
     */
    public function getUidKey(int|string $uid): string
    {
        if ($uid == Imap::ST_UID || $uid == Imap::FT_UID) {
            return 'UID';
        }

        if (strlen($uid) > 0 && ! is_numeric($uid)) {
            return (string) $uid;
        }

        return '';
    }

    /**
     * Build a UID / MSGN command.
     */
    public function buildUidCommand(string $command, int|string $uid): string
    {
        return trim($this->getUidKey($uid).' '.$command);
    }

    /**
     * Set the uid cache of current active folder.
     */
    public function setUidCache(?array $uids): void
    {
        if (is_null($uids)) {
            $this->uidCache = [];

            return;
        }

        $messageNumber = 1;

        $uidCache = [];

        foreach ($uids as $uid) {
            $uidCache[$messageNumber++] = (int) $uid;
        }

        $this->uidCache = $uidCache;
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
     * Check if the current session is connected.
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
}
