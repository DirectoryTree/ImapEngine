<?php

namespace DirectoryTree\ImapEngine;

use DirectoryTree\ImapEngine\Connection\ConnectionInterface;
use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Connection\Tokens\Atom;

class Mailbox
{
    /**
     * The mailbox configuration.
     */
    protected array $config = [
        'port' => 993,
        'host' => '',
        'timeout' => 30,
        'debug' => false,
        'username' => '',
        'password' => '',
        'encryption' => 'ssl',
        'validate_cert' => true,
        'authentication' => 'plain',
        'proxy' => [
            'socket' => null,
            'username' => null,
            'password' => null,
            'request_fulluri' => false,
        ],
    ];

    /**
     * The currently selected folder.
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
        return (bool) $this->connection?->connected();
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
     * Force a reconnection to the IMAP server.
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

        $this->connection = $connection ?? new ImapConnection;

        $this->connection->setProxy($this->config('proxy'));
        $this->connection->setDebug($this->config('debug'));
        $this->connection->setEncryption($this->config('encryption'));
        $this->connection->setConnectionTimeout($this->config('timeout'));
        $this->connection->setCertValidation($this->config('validate_cert'));

        $this->connection->connect($this->config('host'), $this->config('port'));

        $this->authenticate();
    }

    /**
     * Authenticate the current session.
     */
    protected function authenticate(): void
    {
        if ($this->config('authentication') === 'oauth') {
            $this->connection->authenticate(
                $this->config('username'),
                $this->config('password')
            );
        } else {
            $this->connection->login(
                $this->config('username'),
                $this->config('password'),
            );
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
     * Get the mailbox's inbox folder.
     */
    public function inbox(): Folder
    {
        return $this->folders()->find('INBOX');
    }

    /**
     * Begin querying for mailbox folders.
     */
    public function folders(): FolderRepository
    {
        return new FolderRepository(
            tap($this)->connection()
        );
    }

    /**
     * Get the mailbox's capabilities.
     */
    public function capabilities(): array
    {
        return array_map(
            fn (Atom $token) => $token->value,
            $this->connection()->capability()->tokensAfter(1)
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
}
