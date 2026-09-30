<?php

use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
use DirectoryTree\ImapEngine\Connection\Tokens\Atom;
use DirectoryTree\ImapEngine\Connection\Tokens\Number;
use DirectoryTree\ImapEngine\Folder;
use DirectoryTree\ImapEngine\Idle\Events\FolderSelected;
use DirectoryTree\ImapEngine\Idle\Events\MessagesExist;
use DirectoryTree\ImapEngine\Mailbox;
use DirectoryTree\ImapEngine\MessageInterface;
use DirectoryTree\ImapEngine\Selection\OptionInterface;
use DirectoryTree\ImapEngine\Selection\Result;

test('idle tracks arrivals across count notifications', function (?int $uidNext, int $notifications, array $responses, array $expected) {
    $connection = ImapConnection::fake(['* OK Welcome', 'TAG1 OK LOGIN completed', ...$responses]);
    $mailbox = new Mailbox;
    $mailbox->connect($connection);
    $folder = new class($mailbox, 'INBOX', $uidNext, $notifications) extends Folder
    {
        public function __construct(Mailbox $mailbox, string $path, protected ?int $uidNext, protected int $notifications)
        {
            parent::__construct($mailbox, $path);
        }

        public function events(callable $callback, callable|int $timeout = 300, OptionInterface ...$options): void
        {
            $callback(new FolderSelected($this->path, new Result(uidValidity: 1, uidNext: $this->uidNext)));

            for ($i = 0; $i < $this->notifications; $i++) {
                if ($callback(new MessagesExist($this->path, new UntaggedResponse([
                    new Atom('*'), new Number('3'), new Atom('EXISTS'),
                ]))) === false) {
                    break;
                }
            }
        }
    };
    $received = [];

    $folder->idle(function (MessageInterface $message) use (&$received) {
        $received[] = $message->uid();
    });

    expect($received)->toBe($expected);
    $connection->stream()->assertNotWritten('BODY[');
    $connection->disconnect();
})->with([
    'multiple arrivals and duplicate count notification' => [7, 2, [
        '* OK [UIDVALIDITY 1]', 'TAG2 OK SELECT completed',
        '* SEARCH 7 9', 'TAG3 OK SEARCH completed',
        '* 3 FETCH (UID 9 FLAGS ())', '* 2 FETCH (UID 7 FLAGS ())', 'TAG4 OK FETCH completed',
        '* OK [UIDVALIDITY 1]', 'TAG5 OK SELECT completed',
        '* SEARCH 9', 'TAG6 OK SEARCH completed',
        '* 3 FETCH (UID 9 FLAGS ())', 'TAG7 OK FETCH completed',
    ], [7, 9]],
    'message removed before fetch' => [7, 1, [
        '* OK [UIDVALIDITY 1]', 'TAG2 OK SELECT completed',
        '* SEARCH 7', 'TAG3 OK SEARCH completed', 'TAG4 OK FETCH completed',
    ], []],
    'application connection has different uid validity' => [7, 1, [
        '* OK [UIDVALIDITY 2]', 'TAG2 OK SELECT completed',
    ], []],
    'missing uidnext snapshots existing messages' => [null, 1, [
        '* OK [UIDVALIDITY 1]', 'TAG2 OK SELECT completed',
        '* SEARCH 2 6', 'TAG3 OK SEARCH completed',
        '* OK [UIDVALIDITY 1]', 'TAG4 OK SELECT completed',
        '* SEARCH 7', 'TAG5 OK SEARCH completed',
        '* 3 FETCH (UID 7 FLAGS ())', 'TAG6 OK FETCH completed',
    ], [7]],
    'initially empty folder delivers its first arrival' => [1, 1, [
        '* OK [UIDVALIDITY 1]', 'TAG2 OK SELECT completed',
        '* SEARCH 1', 'TAG3 OK SEARCH completed',
        '* 1 FETCH (UID 1 FLAGS ())', 'TAG4 OK FETCH completed',
    ], [1]],
]);

test('idle reconciles a reconnect selection before waiting for another notification', function (int $validity, array $expected, mixed $stopped) {
    $connection = ImapConnection::fake([
        '* OK Welcome', 'TAG1 OK LOGIN completed',
        '* OK [UIDVALIDITY 1]', 'TAG2 OK SELECT completed',
        '* SEARCH 7', 'TAG3 OK SEARCH completed',
        '* 1 FETCH (UID 7 FLAGS ())', 'TAG4 OK FETCH completed',
    ]);
    $mailbox = new Mailbox;
    $mailbox->connect($connection);
    $folder = new class($mailbox, 'INBOX', $validity) extends Folder
    {
        public function __construct(Mailbox $mailbox, string $path, protected int $validity)
        {
            parent::__construct($mailbox, $path);
        }

        public mixed $stopped = null;

        public function events(callable $callback, callable|int $timeout = 300, OptionInterface ...$options): void
        {
            $callback(new FolderSelected($this->path, new Result(uidValidity: 1, uidNext: 7)));
            $this->stopped = $callback(new FolderSelected($this->path, new Result(uidValidity: $this->validity, uidNext: 8)));
        }
    };
    $received = [];

    $folder->idle(function (MessageInterface $message) use (&$received) {
        $received[] = $message->uid();

        return false;
    });

    expect($received)->toBe($expected);
    expect($folder->stopped)->toBe($stopped);
    $connection->disconnect();
})->with([
    'unchanged validity delivers pending arrivals and honors stop' => [1, [7], false],
    'changed validity starts a new arrival baseline' => [2, [], null],
]);
