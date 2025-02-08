<?php

namespace DirectoryTree\ImapEngine\Connection;

use DirectoryTree\ImapEngine\Collections\ResponseCollection;
use DirectoryTree\ImapEngine\Connection\Responses\TaggedResponse;
use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;

interface ConnectionInterface
{
    /**
     * Open a new connection.
     */
    public function connect(string $host, ?int $port = null): void;

    /**
     * Check if the current session is connected.
     */
    public function connected(): bool;

    /**
     * Login to a new session.
     */
    public function login(string $user, string $password): TaggedResponse;

    /**
     * Logout of the current server session.
     */
    public function logout(): ?TaggedResponse;

    /**
     * Authenticate the current session.
     */
    public function authenticate(string $user, string $token): TaggedResponse;

    /**
     * Send idle command.
     */
    public function idle(): void;

    /**
     * Send done command.
     */
    public function done(): void;

    /**
     * Send noop command.
     */
    public function noop(): TaggedResponse;

    /**
     * Apply session saved changes to the server.
     */
    public function expunge(): ResponseCollection;

    /**
     * Get the mailboxes available capabilities.
     */
    public function capability(): UntaggedResponse;

    /**
     * Execute a search request.
     */
    public function search(array $params): UntaggedResponse;

    /**
     * Exchange identification information.
     *
     * @see https://datatracker.ietf.org/doc/html/rfc2971.
     */
    public function id(?array $ids = null): UntaggedResponse;

    /**
     * Fetch message UIDs using the given message numbers.
     */
    public function uids(int|array $msgns): ResponseCollection;

    /**
     * Fetch message contents.
     */
    public function contents(int|array $ids, bool $peek = true): ResponseCollection;

    /**
     * Fetch message headers.
     */
    public function headers(int|array $ids, bool $peek = true): ResponseCollection;

    /**
     * Fetch message flags.
     */
    public function flags(int|array $ids): ResponseCollection;

    /**
     * Fetch message sizes.
     */
    public function sizes(int|array $ids): ResponseCollection;

    /**
     * Select the given folder.
     */
    public function selectFolder(string $folder): ResponseCollection;

    /**
     * Examine a given folder.
     */
    public function examineFolder(string $folder): ResponseCollection;

    /**
     * Get a list of available folders.
     */
    public function folders(string $reference = '', string $folder = '*'): ResponseCollection;

    /**
     * Get the status of a given folder.
     */
    public function folderStatus(string $folder, array $arguments = ['MESSAGES', 'UNSEEN', 'RECENT', 'UIDNEXT', 'UIDVALIDITY']): UntaggedResponse;

    /**
     * Set message flags.
     */
    public function store(array|string $flags, int $from, ?int $to = null, ?string $mode = null, bool $silent = true, ?string $item = null): ResponseCollection;

    /**
     * Append a new message to given folder.
     */
    public function appendMessage(string $folder, string $message, ?array $flags = null, ?string $date = null): ResponseCollection;

    /**
     * Copy message set from current folder to other folder.
     */
    public function copyMessage(string $folder, $from, ?int $to = null): ResponseCollection;

    /**
     * Copy multiple messages to the target folder.
     */
    public function copyManyMessages(array $messages, string $folder): ResponseCollection;

    /**
     * Move a message set from current folder to another folder.
     */
    public function moveMessage(string $folder, $from, ?int $to = null): ResponseCollection;

    /**
     * Move multiple messages to the target folder.
     */
    public function moveManyMessages(array $messages, string $folder): ResponseCollection;

    /**
     * Create a new folder.
     */
    public function createFolder(string $folder): ResponseCollection;

    /**
     * Delete a folder.
     */
    public function deleteFolder(string $folder): TaggedResponse;

    /**
     * Subscribe to a folder.
     */
    public function subscribeFolder(string $folder): TaggedResponse;

    /**
     * Unsubscribe from a folder.
     */
    public function unsubscribeFolder(string $folder): TaggedResponse;

    /**
     * Rename an existing folder.
     */
    public function renameFolder(string $oldPath, string $newPath): TaggedResponse;
}
