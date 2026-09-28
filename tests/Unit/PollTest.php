<?php

use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Exceptions\Exception;
use DirectoryTree\ImapEngine\Exceptions\ImapConnectionClosedException;
use DirectoryTree\ImapEngine\Folder;
use DirectoryTree\ImapEngine\FolderInterface;
use DirectoryTree\ImapEngine\Mailbox;
use DirectoryTree\ImapEngine\Poll;
use DirectoryTree\ImapEngine\Testing\FakeMessage;

test('poll propagates callback exceptions without reconnecting', function (string $exceptionClass) {
    $poll = new class(new Mailbox, 'INBOX', 1) extends Poll
    {
        public int $reconnects = 0;

        public bool $disconnected = false;

        protected function disconnect(): void
        {
            $this->disconnected = true;
        }

        protected function connect(): void {}

        protected function check(callable $query): Generator
        {
            yield new FakeMessage(1);
        }

        protected function reconnect(): void
        {
            $this->reconnects++;
            throw new RuntimeException('Unexpected reconnect');
        }
    };
    $exception = new $exceptionClass('Callback failed');

    expect(fn () => $poll->start(function () use ($exception) {
        throw $exception;
    }, fn ($query) => $query))->toThrow($exception);
    expect($poll->reconnects)->toBe(0);
    expect($poll->disconnected)->toBeTrue();
})->with([RuntimeException::class, Exception::class, ImapConnectionClosedException::class]);

test('poll still reconnects when retrieving messages loses the connection', function () {
    $poll = new class(new Mailbox, 'INBOX', 1) extends Poll
    {
        public int $reconnects = 0;

        protected function connect(): void {}

        protected function check(callable $query): Generator
        {
            throw new ImapConnectionClosedException('Connection lost');
        }

        protected function reconnect(): void
        {
            $this->reconnects++;
            $this->frequency = 0;
        }
    };

    $poll->start(fn () => null, fn ($query) => $query);

    expect($poll->reconnects)->toBe(1);
});

test('poll stops and disconnects immediately when the callback returns false', function () {
    $poll = new class(new Mailbox, 'INBOX', 60) extends Poll
    {
        public bool $disconnected = false;

        protected function connect(): void {}

        protected function check(callable $query): Generator
        {
            yield new FakeMessage(1);
            throw new RuntimeException('Polling should have stopped');
        }

        protected function disconnect(): void
        {
            $this->disconnected = true;
        }
    };
    $received = [];

    $poll->start(function ($message) use (&$received) {
        $received[] = $message->uid();

        return false;
    }, fn ($query) => $query);

    expect($received)->toBe([1]);
    expect($poll->disconnected)->toBeTrue();
});

test('poll preserves or resets its uid baseline when selecting again', function (int $validity, int $uidNext, array $expected) {
    $connection = ImapConnection::fake([
        '* OK Welcome', 'TAG1 OK LOGIN completed',
        '* OK [UIDVALIDITY 1]', '* OK [UIDNEXT 1]', 'TAG2 OK SELECT completed',
        '* OK [UIDVALIDITY 1]', '* OK [UIDNEXT 4]', 'TAG3 OK SELECT completed',
        '* SEARCH 3 1 2', 'TAG4 OK SEARCH completed',
        '* 3 FETCH (UID 3 FLAGS ())', '* 1 FETCH (UID 1 FLAGS ())', '* 2 FETCH (UID 2 FLAGS ())', 'TAG5 OK FETCH completed',
        "* OK [UIDVALIDITY {$validity}]", "* OK [UIDNEXT {$uidNext}]", 'TAG6 OK SELECT completed',
        '* SEARCH 1 3 4', 'TAG7 OK SEARCH completed',
        '* 1 FETCH (UID 1 FLAGS ())', '* 3 FETCH (UID 3 FLAGS ())', '* 4 FETCH (UID 4 FLAGS ())', 'TAG8 OK FETCH completed',
    ]);
    $mailbox = new Mailbox;
    $mailbox->connect($connection);
    $poll = new class($mailbox, 'INBOX', 1) extends Poll
    {
        public function initialize(): void
        {
            $this->select(new Folder($this->mailbox, $this->folder));
        }

        protected function folder(): FolderInterface
        {
            return new Folder($this->mailbox, $this->folder);
        }

        public function read(): array
        {
            return iterator_to_array($this->check(fn ($query) => $query));
        }
    };
    $poll->initialize();

    expect(array_map(fn ($message) => $message->uid(), $poll->read()))->toBe([1, 2, 3]);
    expect(array_map(fn ($message) => $message->uid(), $poll->read()))->toBe($expected);
    $connection->disconnect();
})->with([
    'same validity excludes delivered and older UIDs' => [1, 5, [4]],
    'changed validity starts a new baseline' => [2, 3, [3, 4]],
]);
