<?php

namespace DirectoryTree\ImapEngine;

use DirectoryTree\ImapEngine\Idle\Events\EventInterface;
use DirectoryTree\ImapEngine\Selection\OptionInterface;
use DirectoryTree\ImapEngine\Selection\Result;

interface FolderInterface
{
    /**
     * Get the folder's mailbox.
     */
    public function mailbox(): MailboxInterface;

    /**
     * Get the folder path.
     */
    public function path(): string;

    /**
     * Get the folder attributes.
     *
     * @return string[]
     */
    public function attributes(): array;

    /**
     * Get the folder delimiter.
     */
    public function delimiter(): string;

    /**
     * Get the folder name.
     */
    public function name(): string;

    /**
     * Determine if the current folder is the same as the given.
     */
    public function is(FolderInterface $folder): bool;

    /**
     * Begin querying for messages.
     */
    public function messages(): MessageQueryInterface;

    /**
     * Watch mailbox events on a dedicated connection.
     *
     * Events expose server responses without retrieving messages.
     *
     * Return false from the callback to stop. The timeout controls renewal,
     * not the total watching duration. A callable timeout may return false
     * or zero to stop between sessions.
     *
     * @param  callable(EventInterface): mixed  $callback
     * @param  callable|int  $timeout  The renewal interval in seconds, or a callable returning it.
     */
    public function events(callable $callback, callable|int $timeout = 300, OptionInterface ...$options): void;

    /**
     * Watch for new messages using IDLE, optionally customizing their query.
     *
     * Existing messages are excluded. Reconnects preserve the arrival cursor
     * unless UID validity changes. Arrivals are fetched on count notifications.
     * Return false from the callback to stop. Callback exceptions propagate.
     * The timeout controls IDLE renewal, not the total watching duration.
     *
     * @param  callable(MessageInterface): mixed  $callback
     * @param  callable(MessageQueryInterface): MessageQueryInterface|null  $query
     */
    public function idle(callable $callback, ?callable $query = null, callable|int $timeout = 300, OptionInterface ...$options): void;

    /**
     * Begin polling for new messages at the given frequency in seconds.
     *
     * Callback exceptions propagate to the caller.
     */
    public function poll(callable $callback, ?callable $query = null, callable|int $frequency = 60): void;

    /**
     * Move or rename the current folder.
     */
    public function move(string $newPath): void;

    /**
     * Select the current folder.
     */
    public function select(bool $force = false, OptionInterface ...$options): Result;

    /**
     * Get the folder's quotas.
     */
    public function quota(): array;

    /**
     * Get the folder's status.
     */
    public function status(): array;

    /**
     * Examine the current folder and get detailed status information.
     */
    public function examine(): array;

    /**
     * Expunge the mailbox and return the expunged message sequence numbers.
     */
    public function expunge(array|int|null $uids = null): array;

    /**
     * Delete the current folder.
     */
    public function delete(): void;
}
