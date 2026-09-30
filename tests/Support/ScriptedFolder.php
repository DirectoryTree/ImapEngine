<?php

namespace Tests\Support;

use DirectoryTree\ImapEngine\Folder;
use DirectoryTree\ImapEngine\Idle\Events\EventInterface;
use DirectoryTree\ImapEngine\Mailbox;
use DirectoryTree\ImapEngine\Selection\OptionInterface;

class ScriptedFolder extends Folder
{
    /**
     * Create a folder with events to deliver to its watcher.
     *
     * @param  array<EventInterface>  $events
     */
    public function __construct(Mailbox $mailbox, string $path, protected array $events)
    {
        parent::__construct($mailbox, $path);
    }

    /**
     * Deliver scripted events until the consumer stops watching.
     */
    public function events(callable $callback, callable|int $timeout = 300, OptionInterface ...$options): void
    {
        foreach ($this->events as $event) {
            if ($callback($event) === false) {
                break;
            }
        }
    }
}
