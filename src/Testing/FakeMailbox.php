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
        if (is_null($key)) {
            return $this->config;
        }

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
    public function reconnect(): void
    {
        // Do nothing.
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
    public function enable(string ...$capabilities): ResponseCollection
    {
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
    public function selected(FolderInterface $folder): bool
    {
        return $this->selected?->is($folder) ?? false;
    }
}
