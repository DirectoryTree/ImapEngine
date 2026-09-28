<?php

use Carbon\Carbon;
use DirectoryTree\ImapEngine\Connection\ConnectionInterface;
use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Connection\Streams\FakeStream;
use DirectoryTree\ImapEngine\Exceptions\ImapConnectionClosedException;
use DirectoryTree\ImapEngine\Idle;
use DirectoryTree\ImapEngine\Idle\Events\EventInterface;
use DirectoryTree\ImapEngine\Idle\Events\FolderSelected;
use DirectoryTree\ImapEngine\Idle\Events\MessageExpunged;
use DirectoryTree\ImapEngine\Idle\Events\MessageFetched;
use DirectoryTree\ImapEngine\Idle\Events\MessagesExist;
use DirectoryTree\ImapEngine\Mailbox;

test('watcher delivers mailbox changes without fetching and closes when stopped', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* OK Welcome', 'TAG1 OK LOGIN completed',
        '* LIST () "/" "INBOX"', 'TAG2 OK LIST completed',
        '* 1 EXISTS', '* OK [UIDVALIDITY 777]', 'TAG3 OK SELECT completed',
        '+ idling', '* 4 EXISTS', '* 2 FETCH (FLAGS (\\Seen))', '* 3 EXPUNGE',
    ]);
    $mailbox = new Mailbox;
    $mailbox->connect(new ImapConnection($stream));
    $received = [];

    (new Idle($mailbox, 'INBOX', 30))->await(function (EventInterface $event) use (&$received) {
        $received[] = $event;

        return ! $event instanceof MessageExpunged;
    });

    expect(array_map(fn (EventInterface $event) => $event::class, $received))->toBe([
        FolderSelected::class, MessagesExist::class, MessageFetched::class, MessageExpunged::class,
    ]);
    expect($received[0]->selection()->uidValidity())->toBe(777);
    expect($received[1]->count())->toBe(4);
    expect($stream->opened())->toBeFalse();
    $stream->assertNotWritten('FETCH');
    $stream->assertNotWritten('LOGOUT');
});

test('callback connection exceptions propagate instead of causing a reconnect', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* OK Welcome', 'TAG1 OK LOGIN completed',
        '* LIST () "/" "INBOX"', 'TAG2 OK LIST completed',
        'TAG3 OK SELECT completed', '+ idling', '* 4 EXISTS',
    ]);
    $mailbox = new Mailbox;
    $mailbox->connect(new ImapConnection($stream));
    $exception = new ImapConnectionClosedException('Application callback failed');

    expect(fn () => (new Idle($mailbox, 'INBOX', 30))->await(function (EventInterface $event) use ($exception) {
        if ($event instanceof MessagesExist) {
            throw $exception;
        }
    }))->toThrow($exception);

    expect($stream->opened())->toBeFalse();
});

test('watcher emits a fresh selection after reconnecting', function () {
    $first = new FakeStream;
    $first->open();
    $first->feed([
        '* OK Welcome', 'TAG1 OK LOGIN completed',
        '* LIST () "/" "INBOX"', 'TAG2 OK LIST completed',
        '* OK [UIDVALIDITY 777]', 'TAG3 OK SELECT completed',
        '+ idling', '* BYE Restarting',
    ]);
    $second = new FakeStream;
    $second->open();
    $second->feed([
        '* OK Welcome', 'TAG1 OK LOGIN completed',
        '* OK [UIDVALIDITY 888]', 'TAG2 OK SELECT completed',
    ]);
    $mailbox = new class([new ImapConnection($first), new ImapConnection($second)]) extends Mailbox
    {
        public function __construct(protected array $connections)
        {
            parent::__construct();
        }

        public function connect(?ConnectionInterface $connection = null): void
        {
            parent::connect($connection ?? array_shift($this->connections));
        }
    };
    $validities = [];

    (new Idle($mailbox, 'INBOX', 30))->await(function (EventInterface $event) use (&$validities) {
        expect($event)->toBeInstanceOf(FolderSelected::class);
        $validities[] = $event->selection()->uidValidity();

        return count($validities) < 2;
    });

    expect($validities)->toBe([777, 888]);
    expect($first->opened())->toBeFalse();
    expect($second->opened())->toBeFalse();
    $second->assertWritten('TAG2 SELECT "INBOX"');
    $second->assertNotWritten('LIST');
});

test('watcher does not resolve the folder when stopped before starting', function (bool $callable) {
    $mailbox = new class extends Mailbox
    {
        public function connect(?ConnectionInterface $connection = null): void
        {
            throw new LogicException('The stopped watcher must not connect.');
        }
    };
    $received = [];
    $timeout = $callable ? fn () => false : 0;

    (new Idle($mailbox, 'INBOX', $timeout))->await(function (EventInterface $event) use (&$received) {
        $received[] = $event;
    });

    expect($received)->toBeEmpty();
})->with([
    'zero interval' => false,
    'stopped callback' => true,
]);

test('watcher honors renewal intervals longer than 29 minutes', function () {
    Carbon::setTestNow('2026-09-27 12:00:00');
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* OK Welcome', 'TAG1 OK LOGIN completed',
        '* LIST () "/" "INBOX"', 'TAG2 OK LIST completed',
        'TAG3 OK SELECT completed',
        '+ idling', '* 4 EXISTS', '* 5 EXISTS', '* 3 EXPUNGE', 'TAG4 OK IDLE completed',
        '+ idling', '* 6 EXISTS',
    ]);
    $mailbox = new Mailbox;
    $mailbox->connect(new ImapConnection($stream));
    $received = [];

    try {
        (new Idle($mailbox, 'INBOX', 3600))->await(function (EventInterface $event) use ($stream, &$received) {
            $received[] = $event::class;

            if ($event instanceof MessagesExist && $event->count() === 4) {
                Carbon::setTestNow(Carbon::now()->addMinutes(30));
            }

            if ($event instanceof MessagesExist && $event->count() === 5) {
                $stream->assertNotWritten('DONE');
                Carbon::setTestNow(Carbon::now()->addMinutes(31));
            }

            return ! ($event instanceof MessagesExist && $event->count() === 6);
        });
    } finally {
        Carbon::setTestNow();
    }

    expect($received)->toBe([
        FolderSelected::class, MessagesExist::class, MessagesExist::class,
        MessageExpunged::class, MessagesExist::class,
    ]);
    $stream->assertWritten('DONE');
    $stream->assertWritten('TAG5 IDLE');
});

test('watcher reads the mailbox timeout when finishing idle', function () {
    Carbon::setTestNow('2026-09-27 12:00:00');
    $stream = new class extends FakeStream
    {
        public ?int $timeout = null;

        public function setTimeout(int $seconds): bool
        {
            $this->timeout = $seconds;

            return true;
        }
    };
    $stream->feed([
        '* OK Welcome', 'TAG1 OK LOGIN completed',
        '* LIST () "/" "INBOX"', 'TAG2 OK LIST completed',
        'TAG3 OK SELECT completed',
        '+ idling', '* 4 EXISTS', '* 3 EXPUNGE', 'TAG4 OK IDLE completed',
    ]);
    $mailbox = new class extends Mailbox
    {
        public int $timeout = 5;

        public function config(?string $key = null, mixed $default = null): mixed
        {
            return $key === 'timeout' ? $this->timeout : parent::config($key, $default);
        }
    };
    $completionTimeout = null;

    try {
        $mailbox->connect(new ImapConnection($stream));

        (new Idle($mailbox, 'INBOX', 30))->await(function (EventInterface $event) use ($mailbox, $stream, &$completionTimeout) {
            if ($event instanceof MessagesExist) {
                $mailbox->timeout = 90;
                Carbon::setTestNow(Carbon::now()->addSeconds(31));
            }

            if ($event instanceof MessageExpunged) {
                $completionTimeout = $stream->timeout;

                return false;
            }
        });

        expect($completionTimeout)->toBe(90);
        $stream->assertWritten('DONE');
    } finally {
        $mailbox->disconnect();
        Carbon::setTestNow();
    }
});

test('watcher renews busy idle sessions and preserves queued updates', function () {
    Carbon::setTestNow('2026-09-27 12:00:00');
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* OK Welcome', 'TAG1 OK LOGIN completed',
        '* LIST () "/" "INBOX"', 'TAG2 OK LIST completed',
        'TAG3 OK SELECT completed',
        '+ idling', '* 4 EXISTS', '* 3 EXPUNGE', 'TAG4 OK IDLE completed',
        '+ idling', '* 5 EXISTS',
    ]);
    $mailbox = new Mailbox;
    $mailbox->connect(new ImapConnection($stream));
    $received = [];

    try {
        (new Idle($mailbox, 'INBOX', 30))->await(function (EventInterface $event) use (&$received) {
            $received[] = $event::class;

            if ($event instanceof MessagesExist && $event->count() === 4) {
                Carbon::setTestNow(Carbon::now()->addSeconds(31));
            }

            return ! ($event instanceof MessagesExist && $event->count() === 5);
        });
    } finally {
        Carbon::setTestNow();
    }

    expect($received)->toBe([FolderSelected::class, MessagesExist::class, MessageExpunged::class, MessagesExist::class]);
    $stream->assertWritten('DONE');
    $stream->assertWritten('TAG5 IDLE');
});
