<?php

namespace DirectoryTree\ImapEngine\Connection;

use DirectoryTree\ImapEngine\Imap;

interface ConnectionInterface
{
    /**
     * Open a new connection / session.
     *
     * @param  string  $host  hostname or IP address of IMAP server
     * @param  int|null  $port  of service server
     */
    public function connect(string $host, ?int $port = null): void;

    /**
     * Login to a new session.
     *
     * @param  string  $user  username
     * @param  string  $password  password
     */
    public function login(string $user, string $password): Response;

    /**
     * Authenticate your current session.
     *
     * @param  string  $user  username
     * @param  string  $token  access token
     */
    public function authenticate(string $user, string $token): Response;

    /**
     * Logout of the current server session.
     */
    public function logout(): Response;

    /**
     * Check if the current session is connected.
     */
    public function connected(): bool;

    /**
     * Change the current folder.
     *
     * @param  string  $folder  change to this folder
     * @return Response see examineOrSelect()
     */
    public function selectFolder(string $folder = 'INBOX'): Response;

    /**
     * Examine a given folder.
     */
    public function examineFolder(string $folder = 'INBOX'): Response;

    /**
     * Get the status of a given folder.
     *
     * @return Response list of STATUS items
     */
    public function folderStatus(string $folder = 'INBOX', array $arguments = ['MESSAGES', 'UNSEEN', 'RECENT', 'UIDNEXT', 'UIDVALIDITY']): Response;

    /**
     * Fetch message UIDs using the given message numbers.
     */
    public function uids(int|array $msgns): Response;

    /**
     * Fetch message contents.
     */
    public function contents(int|array $ids, string $rfc = 'RFC822'): Response;

    /**
     * Fetch message headers.
     */
    public function headers(int|array $ids, string $rfc = 'RFC822'): Response;

    /**
     * Fetch message flags.
     */
    public function flags(int|array $ids): Response;

    /**
     * Fetch message sizes.
     */
    public function sizes(int|array $ids): Response;

    /**
     * Get a list of available folders.
     *
     * @param  string  $reference  mailbox reference for list
     * @param  string  $folder  mailbox / folder name match with wildcards
     * @return Response containing mailboxes that matched $folder as array(globalName => array('delim' => .., 'flags' => ..))
     */
    public function folders(string $reference = '', string $folder = '*'): Response;

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
     * @return Response containing the new flags if $silent is false, else true or false depending on success
     */
    public function store(array|string $flags, int $from, ?int $to = null, ?string $mode = null, bool $silent = true, ?string $item = null): Response;

    /**
     * Append a new message to given folder.
     *
     * @param  string  $folder  name of target folder
     * @param  string  $message  full message content
     * @param  array|null  $flags  flags for new message
     * @param  string|null  $date  date for new message
     */
    public function appendMessage(string $folder, string $message, ?array $flags = null, ?string $date = null): Response;

    /**
     * Copy message set from current folder to other folder.
     *
     * @param  string  $folder  destination folder
     * @param  int|null  $to  if null only one message ($from) is fetched, else it's the
     *                        last message, INF means last message available
     */
    public function copyMessage(string $folder, $from, ?int $to = null): Response;

    /**
     * Copy multiple messages to the target folder.
     *
     * @param  array<string>  $messages  List of message identifiers
     * @param  string  $folder  Destination folder
     * @return Response Tokens if operation successful, false if an error occurred
     */
    public function copyManyMessages(array $messages, string $folder): Response;

    /**
     * Move a message set from current folder to another folder.
     *
     * @param  string  $folder  destination folder
     * @param  int|null  $to  if null only one message ($from) is fetched, else it's the
     *                        last message, INF means last message available
     */
    public function moveMessage(string $folder, $from, ?int $to = null): Response;

    /**
     * Move multiple messages to the target folder.
     *
     * @param  array<string>  $messages  List of message identifiers
     * @param  string  $folder  Destination folder
     * @return Response Tokens if operation successful, false if an error occurred
     */
    public function moveManyMessages(array $messages, string $folder): Response;

    /**
     * Exchange identification information.
     *
     * @see https://datatracker.ietf.org/doc/html/rfc2971.
     */
    public function id(?array $ids = null): Response;

    /**
     * Create a new folder.
     *
     * @param  string  $folder  folder name
     */
    public function createFolder(string $folder): Response;

    /**
     * Rename an existing folder.
     *
     * @param  string  $oldPath  old name
     * @param  string  $newPath  new name
     */
    public function renameFolder(string $oldPath, string $newPath): Response;

    /**
     * Delete a folder.
     *
     * @param  string  $folder  folder name
     */
    public function deleteFolder(string $folder): Response;

    /**
     * Subscribe to a folder.
     *
     * @param  string  $folder  folder name
     */
    public function subscribeFolder(string $folder): Response;

    /**
     * Unsubscribe from a folder.
     *
     * @param  string  $folder  folder name
     */
    public function unsubscribeFolder(string $folder): Response;

    /**
     * Send idle command.
     */
    public function idle(): void;

    /**
     * Send done command.
     */
    public function done(): void;

    /**
     * Apply session saved changes to the server.
     */
    public function expunge(): Response;

    /**
     * Get an array of available capabilities.
     *
     * @return Response containing a list of capabilities
     */
    public function capability(): Response;

    /**
     * Retrieve the quota level settings, and usage statics per mailbox.
     */
    public function getQuota(string $username): Response;

    /**
     * Retrieve the quota settings per user.
     */
    public function getQuotaRoot(string $quotaRoot = 'INBOX'): Response;

    /**
     * Send noop command.
     */
    public function noop(): Response;

    /**
     * Execute a search request.
     *
     * @return Response containing the message ids
     */
    public function search(array $params): Response;
}
