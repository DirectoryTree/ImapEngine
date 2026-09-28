<?php

use DirectoryTree\ImapEngine\Exceptions\Exception;
use DirectoryTree\ImapEngine\Exceptions\ImapConnectionClosedException;
use DirectoryTree\ImapEngine\Mailbox;
use DirectoryTree\ImapEngine\Poll;
use DirectoryTree\ImapEngine\Testing\FakeMessage;

test('poll propagates callback exceptions without reconnecting', function (string $exceptionClass) {
    $poll = new class(new Mailbox, 'INBOX', 1) extends Poll
    {
        public int $reconnects = 0;

        protected function connect(): void {}

        protected function check(callable $callback, callable $query): void
        {
            $callback(new FakeMessage(1));
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
})->with([RuntimeException::class, Exception::class, ImapConnectionClosedException::class]);

test('poll still reconnects when retrieving messages loses the connection', function () {
    $poll = new class(new Mailbox, 'INBOX', 1) extends Poll
    {
        public int $reconnects = 0;

        protected function connect(): void {}

        protected function check(callable $callback, callable $query): void
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
