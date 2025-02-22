<?php

namespace DirectoryTree\ImapEngine;

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
     *
     * "INBOX" is a special name reserved for the user's primary mailbox.
     *
     * @see https://datatracker.ietf.org/doc/html/rfc9051#section-5.1
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
     * Select the given folder.
     */
    public function select(FolderInterface $folder, bool $force = false): void;

    /**
     * Determine if the given folder is selected.
     */
    public function selected(FolderInterface $folder): bool;
}
