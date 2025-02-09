<?php

namespace DirectoryTree\ImapEngine;

use DirectoryTree\ImapEngine\Connection\ConnectionInterface;
use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Connection\Loggers\EchoLogger;
use DirectoryTree\ImapEngine\Connection\Loggers\FileLogger;
use DirectoryTree\ImapEngine\Connection\Streams\ImapStream;
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
    public function __clone(): void
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
        return ! $this->isConnected();
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

        $debug = $this->config('debug');

        $this->connection = $connection ?? new ImapConnection(new ImapStream, match (true) {
            class_exists($debug) => new $debug,
            is_string($debug) => new FileLogger($debug),
            is_bool($debug) && $debug => new EchoLogger,
            default => null,
        });

        $this->connection->connect($this->config('host'), $this->config('port'), [
            'proxy' => $this->config('proxy'),
            'debug' => $this->config('debug'),
            'timeout' => $this->config('timeout'),
            'encryption' => $this->config('encryption'),
            'validate_cert' => $this->config('validate_cert'),
        ]);

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
            $this->connection->disconnect();
        }

        $this->connection = null;
    }

    /**
     * Get the mailbox's inbox folder.
     *
     * "INBOX" is a special name reserved for the user's primary mailbox.
     *
     * @see https://datatracker.ietf.org/doc/html/rfc9051#section-5.1
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
            $this->connection()->select($folder->path());
        }

        $this->selected = $folder;
    }
}
