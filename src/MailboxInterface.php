<?php

namespace DirectoryTree\ImapEngine;

use DirectoryTree\ImapEngine\Collections\ResponseCollection;
use DirectoryTree\ImapEngine\Connection\ConnectionInterface;

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
     * Force a reconnection to the server.
     */
    public function reconnect(): void;

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
    public function capabilities(): array;

    /**
     * Determine if the mailbox supports the given capability.
     */
    public function hasCapability(string $capability): bool;

    /**
     * Enable the given mailbox capabilities for the current connection.
     */
    public function enable(string ...$capabilities): ResponseCollection;

    /**
     * Select the given folder.
     */
    public function select(FolderInterface $folder, bool $force = false, SelectionOption ...$options): SelectionResult;

    /**
     * Examine the given folder, invalidating the cached writable selection.
     */
    public function examine(FolderInterface $folder): SelectionResult;

    /**
     * Determine if the given folder is selected.
     */
    public function selected(FolderInterface $folder): bool;
}
