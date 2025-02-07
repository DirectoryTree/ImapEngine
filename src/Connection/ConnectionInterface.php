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
     *
     * @return ResponseCollection containing the message ids
     */
    public function search(array $params): ResponseCollection;

    /**
     * Exchange identification information.
     *
     * @see https://datatracker.ietf.org/doc/html/rfc2971.
     */
    public function id(?array $ids = null): ResponseCollection;

    /**
     * Fetch message UIDs using the given message numbers.
     */
    public function uids(int|array $msgns): UntaggedResponse;

    /**
     * Fetch message contents.
     */
    public function contents(int|array $ids): UntaggedResponse;

    /**
     * Fetch message headers.
     */
    public function headers(int|array $ids): UntaggedResponse;

    /**
     * Fetch message flags.
     */
    public function flags(int|array $ids): UntaggedResponse;

    /**
     * Fetch message sizes.
     */
    public function sizes(int|array $ids): UntaggedResponse;

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
     *
     * @param  string  $reference  mailbox reference for list
     * @param  string  $folder  mailbox / folder name match with wildcards
     * @return ResponseCollection containing mailboxes that matched $folder as array(globalName => array('delim' => .., 'flags' => ..))
     */
    public function folders(string $reference = '', string $folder = '*'): ResponseCollection;

    /**
     * Get the status of a given folder.
     *
     * @return ResponseCollection list of STATUS items
     */
    public function folderStatus(string $folder, array $arguments = ['MESSAGES', 'UNSEEN', 'RECENT', 'UIDNEXT', 'UIDVALIDITY']): UntaggedResponse;

    /**
     * Set message flags.
     *
     * @param  array|string  $flags  flags to set, add or remove
     * @param  int  $from  message for items or start message if $to !== null
     * @param  int|null  $to  if null only one message ($from) is fetched, else it's the
     *                        last message, INF means last message available
     * @param  string|null  $mode  '+' to add flags, '-' to remove flags, everything else sets the flags as given
     * @param  bool  $silent  if false the return values are the new flags for the wanted messages
     * @param  string|null  $item  command used to store a flag
     * @return ResponseCollection containing the new flags if $silent is false, else true or false depending on success
     */
    public function store(array|string $flags, int $from, ?int $to = null, ?string $mode = null, bool $silent = true, ?string $item = null): ResponseCollection;

    /**
     * Append a new message to given folder.
     *
     * @param  string  $folder  name of target folder
     * @param  string  $message  full message content
     * @param  array|null  $flags  flags for new message
     * @param  string|null  $date  date for new message
     */
    public function appendMessage(string $folder, string $message, ?array $flags = null, ?string $date = null): ResponseCollection;

    /**
     * Copy message set from current folder to other folder.
     *
     * @param  string  $folder  destination folder
     * @param  int|null  $to  if null only one message ($from) is fetched, else it's the
     *                        last message, INF means last message available
     */
    public function copyMessage(string $folder, $from, ?int $to = null): ResponseCollection;

    /**
     * Copy multiple messages to the target folder.
     *
     * @param  array<string>  $messages  List of message identifiers
     * @param  string  $folder  Destination folder
     * @return ResponseCollection Tokens if operation successful, false if an error occurred
     */
    public function copyManyMessages(array $messages, string $folder): ResponseCollection;

    /**
     * Move a message set from current folder to another folder.
     *
     * @param  string  $folder  destination folder
     * @param  int|null  $to  if null only one message ($from) is fetched, else it's the
     *                        last message, INF means last message available
     */
    public function moveMessage(string $folder, $from, ?int $to = null): ResponseCollection;

    /**
     * Move multiple messages to the target folder.
     *
     * @param  array<string>  $messages  List of message identifiers
     * @param  string  $folder  Destination folder
     * @return ResponseCollection Tokens if operation successful, false if an error occurred
     */
    public function moveManyMessages(array $messages, string $folder): ResponseCollection;

    /**
     * Create a new folder.
     *
     * @param  string  $folder  folder name
     */
    public function createFolder(string $folder): ResponseCollection;

    /**
     * Delete a folder.
     *
     * @param  string  $folder  folder name
     */
    public function deleteFolder(string $folder): TaggedResponse;

    /**
     * Subscribe to a folder.
     *
     * @param  string  $folder  folder name
     */
    public function subscribeFolder(string $folder): TaggedResponse;

    /**
     * Unsubscribe from a folder.
     *
     * @param  string  $folder  folder name
     */
    public function unsubscribeFolder(string $folder): TaggedResponse;

    /**
     * Rename an existing folder.
     *
     * @param  string  $oldPath  old name
     * @param  string  $newPath  new name
     */
    public function renameFolder(string $oldPath, string $newPath): TaggedResponse;
}
