<?php

namespace DirectoryTree\ImapEngine;

use DirectoryTree\ImapEngine\Collections\ResponseCollection;
use DirectoryTree\ImapEngine\Connection\ConnectionInterface;
use DirectoryTree\ImapEngine\Selection\OptionInterface;
use DirectoryTree\ImapEngine\Selection\Result;

interface MailboxInterface
{
    /**
     * Get mailbox configuration values.
     */
    public function config(?string $key = null, mixed $default = null): mixed;

    /**
     * Get the mailbox connection.
     */
    public function connection(): ConnectionInterface;

    /**
     * Determine if connection was established.
     */
    public function connected(): bool;

    /**
     * Reconnect to the same account, optionally replacing the stored password or token.
     *
     * A null password retains the current credentials. A replacement is retained
     * for subsequent connections, even if authentication fails.
     */
    public function reconnect(?string $password = null): void;

    /**
     * Connect to the server.
     */
    public function connect(?ConnectionInterface $connection = null): void;

    /**
     * Disconnect from server.
     */
    public function disconnect(): void;

    /**
     * Get the mailbox's inbox folder.
     */
    public function inbox(): FolderInterface;

    /**
     * Begin querying for mailbox folders.
     */
    public function folders(): FolderRepositoryInterface;

    /**
     * Get the mailbox's capabilities.
     */
    public function capabilities(): Capabilities;

    /**
     * Enable the given mailbox capabilities for the current connection.
     *
     * Call this before selecting or examining any folder.
     */
    public function enable(string ...$capabilities): ResponseCollection;

    /**
     * Select the given folder.
     */
    public function select(FolderInterface $folder, bool $force = false, OptionInterface ...$options): Result;

    /**
     * Examine the given folder, invalidating the cached writable selection.
     */
    public function examine(FolderInterface $folder): Result;

    /**
     * Determine if the given folder is selected.
     */
    public function selected(FolderInterface $folder): bool;
}
