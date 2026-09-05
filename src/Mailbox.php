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
use DirectoryTree\ImapEngine\Selection\OptionInterface;
use DirectoryTree\ImapEngine\Selection\RequiresEnableInterface;
use DirectoryTree\ImapEngine\Selection\Result;
use Exception;
use InvalidArgumentException;

class Mailbox implements MailboxInterface
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
    protected ?Capabilities $capabilities = null;

    /**
     * The currently selected or examined folder.
     */
    protected ?FolderInterface $folder = null;

    /**
     * The result from the currently selected folder.
     */
    protected ?Result $selection = null;

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
        $this->folder = null;
        $this->selection = null;
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

        if (! is_null($password)) {
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
            'xoauth2' => (new Authentication(
                $this->connection,
                new Authentication\XOAuth2($username, $password),
            ))->authenticate(),
            default => throw new InvalidArgumentException(
                'Unsupported authentication mechanism.'
            ),
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
            $this->folder = null;
            $this->selection = null;
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
    public function capabilities(): Capabilities
    {
        return $this->capabilities ??= Capabilities::from(
            array_map(
                fn (Token $token) => $token->value,
                $this->connection()->capability()->tokensAfter(2)
            )
        );
    }

    /**
     * {@inheritDoc}
     */
    public function enable(string ...$capabilities): ResponseCollection
    {
        $current = $this->capabilities();

        $requested = Capabilities::from($capabilities);

        foreach ($requested->all() as $capability) {
            if (! $current->supports($capability)) {
                throw new ImapCapabilityException(
                    "Unable to enable capability [$capability]. IMAP server does not support it."
                );
            }
        }

        $requested = array_values(array_filter(
            $requested->all(),
            fn (string $capability) => ! $current->enabled($capability),
        ));

        if (empty($requested)) {
            return new ResponseCollection;
        }

        if ($this->folder) {
            throw new ImapCapabilityException(
                'Unable to enable capabilities while a folder is selected or examined. Reconnect before enabling them.'
            );
        }

        $responses = $this->connection()->enable(...$requested);

        foreach ($responses as $response) {
            if ($response->type()->is('ENABLED')) {
                $this->capabilities->enable(
                    ...array_map(fn (Token $token) => $token->value, $response->tokensAfter(2))
                );
            }
        }

        return $responses;
    }

    /**
     * {@inheritDoc}
     */
    public function select(FolderInterface $folder, bool $force = false, OptionInterface ...$options): Result
    {
        foreach ($options as $option) {
            if (! $this->capabilities()->supports($option->capability())) {
                throw new ImapCapabilityException(
                    "Unable to select folder with [{$option->capability()}]. IMAP server does not support it."
                );
            }

            if ($option instanceof RequiresEnableInterface) {
                $this->enable($option->capability());
            }
        }

        if (! $this->selected($folder) || $force || $options) {
            $this->selection = null;

            $selection = $this->connection()->select($folder->path(), ...$options);

            $this->folder = $folder;
            $this->selection = $selection;
        }

        return $this->selection;
    }

    /**
     * {@inheritDoc}
     */
    public function examine(FolderInterface $folder): Result
    {
        // EXAMINE replaces the server selection with a read-only one, even
        // for the same folder. The next query must select it again.
        $this->selection = null;

        $selection = $this->connection()->examine($folder->path());

        $this->folder = $folder;

        return $selection;
    }

    /**
     * {@inheritDoc}
     */
    public function selected(FolderInterface $folder): bool
    {
        return $this->selection && $this->folder?->is($folder);
    }
}
