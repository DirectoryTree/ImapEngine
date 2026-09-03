<?php

namespace DirectoryTree\ImapEngine;

use DirectoryTree\ImapEngine\Collections\ResponseCollection;
use DirectoryTree\ImapEngine\Connection\ConnectionInterface;
use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Connection\Loggers\EchoLogger;
use DirectoryTree\ImapEngine\Connection\Loggers\FileLogger;
use DirectoryTree\ImapEngine\Connection\Streams\ImapStream;
use DirectoryTree\ImapEngine\Connection\Tokens\Token;
use DirectoryTree\ImapEngine\Exceptions\ImapCapabilityException;
use Exception;
use InvalidArgumentException;

class Mailbox implements MailboxInterface
{
    use HasCapabilities;

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
        'authentication' => 'login',
        'proxy' => [
            'socket' => null,
            'username' => null,
            'password' => null,
            'request_fulluri' => false,
        ],
    ];

    /**
     * The cached mailbox capabilities.
     *
     * @see https://datatracker.ietf.org/doc/html/rfc9051#section-6.1.1
     */
    protected ?array $capabilities = null;

    /**
     * The currently selected folder.
     */
    protected ?FolderInterface $selected = null;

    /**
     * The result from the currently selected folder.
     */
    protected ?SelectionResult $selection = null;

    /**
     * The capabilities enabled for the current connection.
     */
    protected array $enabled = [];

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
        $this->capabilities = null;
        $this->selected = null;
        $this->selection = null;
        $this->enabled = [];
    }

    /**
     * Make a new mailbox instance.
     */
    public static function make(array $config = []): static
    {
        return new static($config);
    }

    /**
     * {@inheritDoc}
     */
    public function config(?string $key = null, mixed $default = null): mixed
    {
        return data_get($this->config, $key, $default);
    }

    /**
     * {@inheritDoc}
     */
    public function connection(): ConnectionInterface
    {
        if (! $this->connection) {
            $this->connect();
        }

        return $this->connection;
    }

    /**
     * {@inheritDoc}
     */
    public function connected(): bool
    {
        return (bool) $this->connection?->connected();
    }

    /**
     * {@inheritDoc}
     */
    public function reconnect(?string $password = null): void
    {
        $this->disconnect();

        if ($password !== null) {
            $this->config['password'] = $password;
        }

        $this->connect();
    }

    /**
     * {@inheritDoc}
     */
    public function connect(?ConnectionInterface $connection = null): void
    {
        if ($this->connected()) {
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
        $username = $this->config('username');
        $password = $this->config('password');

        match ($this->config('authentication')) {
            'login' => $this->connection->login($username, $password),
            'xoauth2' => $this->connection->authenticate(
                new Authentication\XOAuth2($username, $password),
            ),
            default => throw new InvalidArgumentException('Unsupported authentication mechanism.'),
        };
    }

    /**
     * {@inheritDoc}
     */
    public function disconnect(): void
    {
        try {
            $this->connection?->logout();
            $this->connection?->disconnect();
        } catch (Exception) {
            // Do nothing.
        } finally {
            $this->connection = null;
            $this->capabilities = null;
            $this->selected = null;
            $this->selection = null;
            $this->enabled = [];
        }
    }

    /**
     * {@inheritDoc}
     */
    public function inbox(): FolderInterface
    {
        // "INBOX" is a special name reserved for the user's primary mailbox.
        // See: https://datatracker.ietf.org/doc/html/rfc9051#section-5.1
        return $this->folders()->find('INBOX');
    }

    /**
     * {@inheritDoc}
     */
    public function folders(): FolderRepositoryInterface
    {
        // Ensure the connection is established.
        $this->connection();

        return new FolderRepository($this);
    }

    /**
     * {@inheritDoc}
     */
    public function capabilities(): array
    {
        return $this->capabilities ??= array_map(
            fn (Token $token) => $token->value,
            $this->connection()->capability()->tokensAfter(2)
        );
    }

    /**
     * {@inheritDoc}
     */
    public function hasEnabledCapability(string $capability): bool
    {
        return in_array(strtoupper($capability), $this->enabled, true);
    }

    /**
     * {@inheritDoc}
     */
    public function enable(string ...$capabilities): ResponseCollection
    {
        $capabilities = array_map('strtoupper', $capabilities);

        foreach ($capabilities as $capability) {
            if (! $this->hasCapability($capability)) {
                throw new ImapCapabilityException(
                    "Unable to enable capability [$capability]. IMAP server does not support it."
                );
            }
        }

        $capabilities = array_values(array_diff($capabilities, $this->enabled));

        if (empty($capabilities)) {
            return new ResponseCollection;
        }

        $responses = $this->connection()->enable(...$capabilities);

        foreach ($responses as $response) {
            if ($response->type()->is('ENABLED')) {
                $this->enabled = array_unique([
                    ...$this->enabled,
                    ...array_map(fn (Token $token) => strtoupper($token->value), $response->tokensAfter(2)),
                ]);
            }
        }

        return $responses;
    }

    /**
     * {@inheritDoc}
     */
    public function select(FolderInterface $folder, bool $force = false, SelectionOption ...$options): SelectionResult
    {
        foreach ($options as $option) {
            if (! $this->hasCapability($option->capability())) {
                throw new ImapCapabilityException(
                    "Unable to select folder with [{$option->capability()}]. IMAP server does not support it."
                );
            }

            if ($option->capability() === 'QRESYNC') {
                $this->enable('QRESYNC');
            }
        }

        if (! $this->selected($folder) || $force || $options) {
            $this->selection = $this->connection()->select($folder->path(), ...$options);
        }

        $this->selected = $folder;

        return $this->selection ?? new SelectionResult;
    }

    /**
     * {@inheritDoc}
     */
    public function examine(FolderInterface $folder): SelectionResult
    {
        // EXAMINE replaces the server selection with a read-only one, even
        // for the same folder. The next query must select it again.
        $this->selected = null;
        $this->selection = null;

        return $this->connection()->examine($folder->path());
    }

    /**
     * {@inheritDoc}
     */
    public function selected(FolderInterface $folder): bool
    {
        return $this->selected?->is($folder) ?? false;
    }
}
