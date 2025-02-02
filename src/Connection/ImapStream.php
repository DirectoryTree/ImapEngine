<?php

namespace DirectoryTree\ImapEngine\Connection;

use DirectoryTree\ImapEngine\Exceptions\ConnectionFailedException;

class ImapStream implements StreamInterface
{
    /**
     * The underlying PHP stream resource.
     *
     * @var resource|null
     */
    protected $stream = null;

    /**
     * {@inheritDoc}
     */
    public function open(string $transport, string $host, int $port, int $timeout, array $options = []): bool
    {
        $remoteSocket = "{$transport}://{$host}:{$port}";

        $this->stream = @stream_socket_client(
            $remoteSocket,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            stream_context_create($options)
        );

        if (! $this->stream) {
            throw new ConnectionFailedException('Stream failed to open: '.$errstr, $errno);
        }

        stream_set_blocking($this->stream, true);

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function close(): void
    {
        if ($this->isOpen()) {
            fclose($this->stream);
        }

        $this->stream = null;
    }

    /**
     * {@inheritDoc}
     */
    public function fgets(): string|false
    {
        if (! $this->isOpen()) {
            return false;
        }

        return fgets($this->stream);
    }

    /**
     * {@inheritDoc}
     */
    public function fwrite(string $data): int|false
    {
        if (! $this->isOpen()) {
            return false;
        }

        return fwrite($this->stream, $data);
    }

    /**
     * {@inheritDoc}
     */
    public function meta(): array
    {
        if (! $this->isOpen()) {
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

        return stream_get_meta_data($this->stream);
    }

    /**
     * {@inheritDoc}
     */
    public function isOpen(): bool
    {
        return is_resource($this->stream);
    }

    /**
     * {@inheritDoc}
     */
    public function setTimeout(int $seconds): bool
    {
        return stream_set_timeout($this->stream, $seconds);
    }

    /**
     * {@inheritDoc}
     */
    public function setSocketSetCrypto(bool $enabled, ?int $method): bool|int
    {
        return stream_socket_enable_crypto($this->stream, $enabled, $method);
    }
}
