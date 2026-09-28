<?php

namespace DirectoryTree\ImapEngine\Testing;

use DirectoryTree\ImapEngine\ComparesFolders;
use DirectoryTree\ImapEngine\Exceptions\Exception;
use DirectoryTree\ImapEngine\FolderInterface;
use DirectoryTree\ImapEngine\Idle\Events\EventInterface;
use DirectoryTree\ImapEngine\Idle\Events\FolderSelected;
use DirectoryTree\ImapEngine\MailboxInterface;
use DirectoryTree\ImapEngine\MessageQueryInterface;
use DirectoryTree\ImapEngine\Selection\OptionInterface;
use DirectoryTree\ImapEngine\Selection\Result;
use DirectoryTree\ImapEngine\Support\Str;

class FakeFolder implements FolderInterface
{
    use ComparesFolders;

    /**
     * The modification sequence at which each message vanished.
     *
     * @var array<int, int>
     */
    protected array $vanished = [];

    /**
     * The mailbox events to deliver while idling.
     *
     * @var EventInterface[]
     */
    protected array $idleEvents = [];

    /**
     * The next UID assigned to an appended message.
     */
    protected int $uidNext;

    /**
     * Constructor.
     */
    public function __construct(
        protected string $path = '',
        protected array $attributes = [],
        /** @var FakeMessage[] */
        protected array $messages = [],
        protected string $delimiter = '/',
        protected ?MailboxInterface $mailbox = null,
    ) {
        $uids = array_map(fn (FakeMessage $message) => $message->uid(), $messages);

        $this->uidNext = $uids ? max($uids) + 1 : 1;
    }

    /**
     * {@inheritDoc}
     */
    public function mailbox(): MailboxInterface
    {
        return $this->mailbox ?? throw new Exception('Folder has no mailbox.');
    }

    /**
     * {@inheritDoc}
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * {@inheritDoc}
     */
    public function attributes(): array
    {
        return $this->attributes;
    }

    /**
     * {@inheritDoc}
     */
    public function delimiter(): string
    {
        return $this->delimiter;
    }

    /**
     * {@inheritDoc}
     */
    public function name(): string
    {
        return Str::fromImapUtf7(
            last(explode($this->delimiter, $this->path))
        );
    }

    /**
     * {@inheritDoc}
     */
    public function is(FolderInterface $folder): bool
    {
        return $this->isSameFolder($this, $folder);
    }

    /**
     * {@inheritDoc}
     */
    public function messages(): MessageQueryInterface
    {
        // Ensure the folder is selected.
        $this->select();

        return new FakeMessageQuery($this);
    }

    /**
     * {@inheritDoc}
     */
    public function events(callable $callback, callable|int $timeout = 300, OptionInterface ...$options): void
    {
        if (! is_numeric($seconds = is_callable($timeout) ? $timeout() : $timeout) || $seconds <= 0) {
            return;
        }

        $selection = new FolderSelected($this->path, $this->select(true, ...$options));

        foreach ([$selection, ...$this->idleEvents] as $event) {
            if ($callback($event) === false) {
                break;
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function idle(callable $callback, ?callable $query = null, callable|int $timeout = 300, OptionInterface ...$options): void
    {
        if (! is_numeric($seconds = is_callable($timeout) ? $timeout() : $timeout) || $seconds <= 0) {
            return;
        }

        $messages = $this->messages();
        $messages = $query ? $query($messages) : $messages;

        foreach ($messages->orderByUid()->cursor() as $message) {
            if ($callback($message) === false) {
                break;
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function poll(callable $callback, ?callable $query = null, callable|int $frequency = 60): void
    {
        if (! is_numeric($seconds = is_callable($frequency) ? $frequency() : $frequency) || $seconds <= 0) {
            return;
        }

        $messages = $this->messages();
        $messages = $query ? $query($messages) : $messages;

        foreach ($messages->orderByUid()->cursor() as $message) {
            if ($callback($message) === false) {
                break;
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function move(string $newPath): void
    {
        // Do nothing.
    }

    /**
     * {@inheritDoc}
     */
    public function select(bool $force = false, OptionInterface ...$options): Result
    {
        return $this->mailbox?->select($this, $force, ...$options) ?? new Result;
    }

    /**
     * {@inheritDoc}
     */
    public function status(): array
    {
        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function examine(): array
    {
        $this->mailbox?->examine($this);

        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function expunge(array|int|null $uids = null): array
    {
        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function quota(): array
    {
        return [
            $this->path => [
                'STORAGE' => [
                    'usage' => 0,
                    'limit' => 0,
                ],
                'MESSAGE' => [
                    'usage' => 0,
                    'limit' => 0,
                ],
            ],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function delete(): void
    {
        // Do nothing.
    }

    /**
     * Set the folder's path.
     */
    public function setPath(string $path): FakeFolder
    {
        $this->path = $path;

        return $this;
    }

    /**
     * Set the folder's attributes.
     */
    public function setAttributes(array $attributes): FakeFolder
    {
        $this->attributes = $attributes;

        return $this;
    }

    /**
     * Set the folder's mailbox.
     */
    public function setMailbox(MailboxInterface $mailbox): FakeFolder
    {
        $this->mailbox = $mailbox;

        return $this;
    }

    /**
     * Set the folder's fake messages.
     *
     * @param  FakeMessage[]  $messages
     */
    public function setMessages(array $messages): FakeFolder
    {
        $this->messages = $messages;

        foreach ($messages as $message) {
            $this->uidNext = max($this->uidNext, $message->uid() + 1);
        }

        return $this;
    }

    /**
     * Set the events delivered after the initial folder selection.
     *
     * @param  EventInterface[]  $events
     */
    public function setIdleEvents(array $events): FakeFolder
    {
        $this->idleEvents = $events;

        return $this;
    }

    /**
     * Get the folder's fake messages.
     *
     * @return FakeMessage[]
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    /**
     * Add a fake message to the folder.
     */
    public function addMessage(FakeMessage $message): void
    {
        $this->messages[] = $message;
        $this->uidNext = max($this->uidNext, $message->uid() + 1);
    }

    /**
     * Get and increment the next message UID.
     */
    public function nextUid(): int
    {
        return $this->uidNext++;
    }

    /**
     * Record a message as vanished at the given modification sequence.
     */
    public function vanish(int $uid, int $modSequence): FakeFolder
    {
        $this->messages = array_values(array_filter(
            $this->messages,
            fn (FakeMessage $message) => $message->uid() !== $uid,
        ));

        $this->vanished[$uid] = $modSequence;
        $this->uidNext = max($this->uidNext, $uid + 1);

        return $this;
    }

    /**
     * Get the requested message UIDs that vanished after the checkpoint.
     *
     * @param  int[]  $uids
     * @return int[]
     */
    public function vanishedSince(int $modSequence, array $uids): array
    {
        return array_keys(array_filter(
            $this->vanished,
            fn (int $vanishedAt, int $uid) => $vanishedAt > $modSequence
                && in_array($uid, $uids, true),
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    /**
     * Set the folder's delimiter.
     */
    public function setDelimiter(string $delimiter = '/'): FakeFolder
    {
        $this->delimiter = $delimiter;

        return $this;
    }
}
