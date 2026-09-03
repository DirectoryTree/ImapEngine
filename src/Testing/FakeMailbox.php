<?php

namespace DirectoryTree\ImapEngine\Testing;

use DirectoryTree\ImapEngine\Collections\ResponseCollection;
use DirectoryTree\ImapEngine\Connection\ConnectionInterface;
use DirectoryTree\ImapEngine\Exceptions\Exception;
use DirectoryTree\ImapEngine\FolderInterface;
use DirectoryTree\ImapEngine\FolderRepositoryInterface;
use DirectoryTree\ImapEngine\HasCapabilities;
use DirectoryTree\ImapEngine\MailboxInterface;
use DirectoryTree\ImapEngine\SelectionOption;
use DirectoryTree\ImapEngine\SelectionResult;

class FakeMailbox implements MailboxInterface
{
    use HasCapabilities;

    /**
     * The currently selected folder.
     */
    protected ?FolderInterface $selected = null;

    /**
     * The capabilities enabled for the current connection.
     */
    protected array $enabled = [];

    /**
     * Constructor.
     */
    public function __construct(
        protected array $config = [],
        /** @var FakeFolder[] */
        protected array $folders = [],
        protected array $capabilities = [],
    ) {
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
        $this->enabled = [];
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
    public function capabilities(): array
    {
        return $this->capabilities;
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
        $this->enabled = array_unique([...$this->enabled, ...array_map('strtoupper', $capabilities)]);

        return new ResponseCollection;
    }

    /**
     * {@inheritDoc}
     */
    public function select(FolderInterface $folder, bool $force = false, SelectionOption ...$options): SelectionResult
    {
        $this->selected = $folder;

        return new SelectionResult;
    }

    /**
     * {@inheritDoc}
     */
    public function examine(FolderInterface $folder): SelectionResult
    {
        $this->selected = null;

        return new SelectionResult;
    }

    /**
     * {@inheritDoc}
     */
    public function selected(FolderInterface $folder): bool
    {
        return $this->selected?->is($folder) ?? false;
    }
}
