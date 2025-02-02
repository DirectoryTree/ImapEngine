<?php

namespace DirectoryTree\ImapEngine;

use DirectoryTree\ImapEngine\Connection\ConnectionInterface;
use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Exceptions\AuthFailedException;
use DirectoryTree\ImapEngine\Exceptions\ConnectionFailedException;
use DirectoryTree\ImapEngine\Exceptions\RuntimeException;
use DirectoryTree\ImapEngine\Query\FolderQuery;
use ErrorException;

class Mailbox
{
    /**
     * The mailbox configuration.
     */
    protected array $config = [
        'port' => 993,
        'host' => '',
        'debug' => false,
        'delimiter' => '/',
        'encryption' => 'ssl',
        'validate_cert' => true,
        'timeout' => 30,
        'username' => '',
        'password' => '',
        'extensions' => [],
        'authentication' => 'PLAIN',
        'proxy' => [
            'socket' => null,
            'request_fulluri' => false,
            'username' => null,
            'password' => null,
        ],
    ];

    /**
     * The selected folder.
     */
    protected ?Folder $selected = null;

    /**
     * The mailbox connection.
     */
    protected ?ConnectionInterface $connection = null;

    /**
     * Constructor.
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge($this->config, $config);
    }

    /**
     * Prepare the cloned instance.
     */
    public function __clone()
    {
        $this->connection = null;
    }

    /**
     * Make a new mailbox instance.
     */
    public static function make(array $config = []): static
    {
        return new static($config);
    }

    /**
     * Get the mailbox configuration.
     */
    public function config(?string $key = null, mixed $default = null): mixed
    {
        if (is_null($key)) {
            return $this->config;
        }

        return data_get($this->config, $key, $default);
    }

    /**
     * Get the mailbox connection.
     */
    public function connection(): ConnectionInterface
    {
        if (! $this->connection) {
            $this->connect();
        }

        return $this->connection;
    }

    /**
     * Determine if connection was established.
     */
    public function isConnected(): bool
    {
        return $this->connection && $this->connection->connected();
    }

    /**
     * Determine if the connection is closed.
     */
    public function isClosed(): bool
    {
        if (! $this->isConnected()) {
            return true;
        }

        $meta = $this->connection->meta();

        return $meta['timed_out'] || $meta['eof'];
    }

    /**
     * Force the client to reconnect.
     */
    public function reconnect(): void
    {
        $this->disconnect();

        $this->connect();
    }

    /**
     * Connect to the IMAP server.
     */
    public function connect(?ConnectionInterface $connection = null): void
    {
        if ($this->isConnected()) {
            return;
        }

        $this->connection = $connection ?? new ImapConnection();

        $this->connection->setProxy($this->config('proxy'));
        $this->connection->setDebug($this->config('debug'));
        $this->connection->setEncryption($this->config('encryption'));
        $this->connection->setConnectionTimeout($this->config('timeout'));
        $this->connection->setCertValidation($this->config('validate_cert'));

        try {
            $this->connection->connect($this->config('host'), $this->config('port'));
        } catch (ErrorException|RuntimeException $e) {
            throw new ConnectionFailedException('Connection setup failed', 0, $e);
        }

        $this->authenticate();
    }

    /**
     * Authenticate the current session.
     */
    protected function authenticate(): void
    {
        if ($this->config('authentication') === 'oauth') {
            $authenticated = $this->connection->authenticate(
                $this->config('username'),
                $this->config('password')
            )->getValidatedData();
        } else {
            $authenticated = $this->connection->login(
                $this->config('username'),
                $this->config('password'),
            )->getValidatedData();
        }

        if (! $authenticated) {
            throw new AuthFailedException('Authentication failed');
        }
    }

    /**
     * Disconnect from server.
     */
    public function disconnect(): void
    {
        if ($this->isConnected()) {
            $this->connection->logout();
        }

        $this->connection = null;
    }

    /**
     * Begin querying for mailbox folders.
     */
    public function folders(): FolderQuery
    {
        return new FolderQuery(
            tap($this)->connection()
        );
    }

    /**
     * Select the given folder.
     */
    public function select(Folder $folder, bool $force = false): void
    {
        if (! $this->selected?->is($folder) || $force) {
            $this->connection()->selectFolder($folder->path());
        }

        $this->selected = $folder;
    }

    /**
     * Set the connection timeout.
     */
    public function setTimeout(int $timeout): ConnectionInterface
    {
        $this->timeout = $timeout;

        if ($this->isConnected()) {
            $this->connection->setConnectionTimeout($timeout);

            $this->reconnect();
        }

        return $this->connection;
    }
}