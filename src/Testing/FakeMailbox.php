<?php

namespace DirectoryTree\ImapEngine\Testing;

use DirectoryTree\ImapEngine\Capabilities;
use DirectoryTree\ImapEngine\Collections\ResponseCollection;
use DirectoryTree\ImapEngine\Connection\ConnectionInterface;
use DirectoryTree\ImapEngine\Exceptions\Exception;
use DirectoryTree\ImapEngine\Exceptions\ImapCapabilityException;
use DirectoryTree\ImapEngine\FolderInterface;
use DirectoryTree\ImapEngine\FolderRepositoryInterface;
use DirectoryTree\ImapEngine\MailboxInterface;
use DirectoryTree\ImapEngine\Selection\OptionInterface;
use DirectoryTree\ImapEngine\Selection\Result;

class FakeMailbox implements MailboxInterface
{
    /**
     * The currently selected folder.
     */
    protected ?FolderInterface $selected = null;

    /**
     * The mailbox capabilities.
     */
    protected Capabilities $capabilities;

    /**
     * Constructor.
     */
    public function __construct(
        protected array $config = [],
        /** @var FakeFolder[] */
        protected array $folders = [],
        array $capabilities = [],
    ) {
        $this->capabilities = Capabilities::from($capabilities);

        foreach ($folders as $folder) {
            $folder->setMailbox($this);
        }
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
        throw new Exception('Unsupported.');
    }

    /**
     * {@inheritDoc}
     */
    public function connected(): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function reconnect(?string $password = null): void
    {
        if ($password !== null) {
            $this->config['password'] = $password;
        }

        $this->selected = null;
        $this->capabilities = Capabilities::from($this->capabilities->all());
    }

    /**
     * {@inheritDoc}
     */
    public function connect(?ConnectionInterface $connection = null): void
    {
        // Do nothing.
    }

    /**
     * {@inheritDoc}
     */
    public function disconnect(): void
    {
        // Do nothing.
    }

    /**
     * {@inheritDoc}
     */
    public function inbox(): FolderInterface
    {
        return $this->folders()->findOrFail('inbox');
    }

    /**
     * {@inheritDoc}
     */
    public function folders(): FolderRepositoryInterface
    {
        return new FakeFolderRepository($this, $this->folders);
    }

    /**
     * {@inheritDoc}
     */
    public function capabilities(): Capabilities
    {
        return $this->capabilities;
    }

    /**
     * {@inheritDoc}
     */
    public function enable(string ...$capabilities): ResponseCollection
    {
        $capabilities = Capabilities::from($capabilities);

        foreach ($capabilities->all() as $capability) {
            if (! $this->capabilities->supports($capability)) {
                throw new ImapCapabilityException(
                    "Unable to enable capability [$capability]. IMAP server does not support it."
                );
            }
        }

        $this->capabilities->enable(...$capabilities->all());

        return new ResponseCollection;
    }

    /**
     * {@inheritDoc}
     */
    public function select(FolderInterface $folder, bool $force = false, OptionInterface ...$options): Result
    {
        $this->selected = $folder;

        return new Result;
    }

    /**
     * {@inheritDoc}
     */
    public function examine(FolderInterface $folder): Result
    {
        $this->selected = null;

        return new Result;
    }

    /**
     * {@inheritDoc}
     */
    public function selected(FolderInterface $folder): bool
    {
        return $this->selected?->is($folder) ?? false;
    }
}
