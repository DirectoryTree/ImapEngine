<?php

namespace DirectoryTree\ImapEngine\Testing;

use DirectoryTree\ImapEngine\Capabilities;
use DirectoryTree\ImapEngine\Capability;
use DirectoryTree\ImapEngine\Collections\FolderCollection;
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
     * Constructor.
     */
    protected function __construct(
        protected array $config,
        protected FolderCollection $folders,
        protected Capabilities $capabilities,
    ) {
        /** @var FakeFolder $folder */
        foreach ($folders as $folder) {
            $folder->setMailbox($this);
        }
    }

    /**
     * Make a new fake mailbox.
     *
     * @param  FakeFolder[]  $folders
     * @param  string[]  $capabilities
     */
    public static function make(array $config = [], array $folders = [], array $capabilities = []): static
    {
        return new static(
            $config,
            new FolderCollection($folders),
            Capabilities::from(
                ...array_map(fn (string $capability) => Capability::make($capability), $capabilities)
            ),
        );
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

        $this->capabilities = Capabilities::from(
            ...array_map(
                fn (string $capability) => Capability::make($capability),
                $this->capabilities->all()
            )
        );
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
        return new FakeFolderRepository($this, $this->folders->all());
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
        $current = $this->capabilities();

        $capabilities = Capabilities::from(
            ...array_map(fn (string $capability) => Capability::make($capability), $capabilities)
        );

        foreach ($capabilities->all() as $capability) {
            if (! $current->has($capability)) {
                throw new ImapCapabilityException(
                    "Unable to enable capability [$capability]. IMAP server does not support it."
                );
            }
        }

        $items = $current->items();

        foreach ($capabilities->all() as $capability) {
            $items[$capability] = Capability::make($capability, enabled: true);
        }

        $this->capabilities = Capabilities::from(...array_values($items));

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
