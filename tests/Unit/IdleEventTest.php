<?php

use DirectoryTree\ImapEngine\Collections\MessageCollection;
use DirectoryTree\ImapEngine\Connection\ImapParser;
use DirectoryTree\ImapEngine\Connection\ImapTokenizer;
use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
use DirectoryTree\ImapEngine\Connection\Streams\FakeStream;
use DirectoryTree\ImapEngine\Connection\Tokens\Atom;
use DirectoryTree\ImapEngine\Connection\Tokens\Number;
use DirectoryTree\ImapEngine\Idle\Events\EventInterface;
use DirectoryTree\ImapEngine\Idle\Events\FolderSelected;
use DirectoryTree\ImapEngine\Idle\Events\MessageExpunged;
use DirectoryTree\ImapEngine\Idle\Events\MessageFetched;
use DirectoryTree\ImapEngine\Idle\Events\MessagesExist;
use DirectoryTree\ImapEngine\Idle\Events\MessagesVanished;
use DirectoryTree\ImapEngine\Idle\Events\ResponseEvent;
use DirectoryTree\ImapEngine\Idle\Events\UnknownEvent;
use DirectoryTree\ImapEngine\Mailbox;
use DirectoryTree\ImapEngine\MessageData;
use DirectoryTree\ImapEngine\MessageInterface;
use DirectoryTree\ImapEngine\MessageQueryInterface;
use DirectoryTree\ImapEngine\Selection\Result;
use DirectoryTree\ImapEngine\Testing\FakeFolder;
use DirectoryTree\ImapEngine\Testing\FakeMessage;
use DirectoryTree\ImapEngine\Watch;

test('idle events preserve counts sequence numbers and synchronization changes', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* 10 EXISTS',
        '* 3 EXPUNGE',
        '* 2 FETCH (UID 42 FLAGS (\\Seen) MODSEQ (99))',
        '* VANISHED (EARLIER) 7:9',
        '* 4 FETCH (FLAGS (\\Flagged))',
    ]);
    $parser = new ImapParser(new ImapTokenizer($stream));

    $exists = new MessagesExist('INBOX', $parser->next());
    expect($exists)->toBeInstanceOf(MessagesExist::class);
    expect($exists->type())->toBe('EXISTS');
    expect($exists->count())->toBe(10);
    expect($exists->folder())->toBe('INBOX');

    $expunge = new MessageExpunged('INBOX', $parser->next());
    expect($expunge)->toBeInstanceOf(MessageExpunged::class);
    expect($expunge->type())->toBe('EXPUNGE');
    expect($expunge->sequenceNumber())->toBe(3);

    $fetch = new MessageFetched('INBOX', $parser->next());
    expect($fetch)->toBeInstanceOf(MessageFetched::class);
    expect($fetch->changes()->messages()[0]->uid())->toBe(42);
    expect($fetch->sequenceNumber())->toBe(2);

    $vanished = new MessagesVanished('INBOX', $parser->next());
    expect($vanished)->toBeInstanceOf(MessagesVanished::class);
    expect($vanished->type())->toBe('VANISHED');
    expect($vanished->uids()->all())->toBe([7, 8, 9]);
    expect($vanished->earlier())->toBeTrue();

    $flags = new MessageFetched('INBOX', $parser->next());
    expect($flags)->toBeInstanceOf(MessageFetched::class);
    expect($flags->changes()->messages()[0]->has('UID'))->toBeFalse();
    expect($flags->sequenceNumber())->toBe(4);
    expect($flags->response())->not->toBeNull();
});

test('selection events expose the reconciliation metadata', function () {
    $event = new FolderSelected('INBOX', $selection = new Result(uidValidity: 777, uidNext: 43));

    expect($event->type())->toBe('SELECT');
    expect($event->selection())->toBe($selection);
    expect($event->selection()->uidValidity())->toBe(777);
    expect($event->folder())->toBe('INBOX');
});

test('watcher converts responses into typed events preserving response identity', function (string $line, string $class, string $type) {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed($line);
    $parser = new ImapParser(new ImapTokenizer($stream));
    $response = $parser->next();

    $watch = new class(new Mailbox, 'Archive', 30) extends Watch
    {
        public function toEvent(UntaggedResponse $response): ResponseEvent
        {
            return parent::toEvent($response);
        }
    };
    $event = $watch->toEvent($response);

    expect($event)->toBeInstanceOf($class)->toBeInstanceOf(EventInterface::class);
    expect($event->folder())->toBe('Archive');
    expect($event->type())->toBe($type);
    expect($event->response())->toBe($response);
})->with([
    'empty mailbox' => ['* 0 EXISTS', MessagesExist::class, 'EXISTS'],
    'fetch update' => ['* 2 FETCH (FLAGS (\\Seen))', MessageFetched::class, 'FETCH'],
    'expunged message' => ['* 3 EXPUNGE', MessageExpunged::class, 'EXPUNGE'],
    'vanished messages' => ['* VANISHED 7:9', MessagesVanished::class, 'VANISHED'],
    'numbered unmodeled response' => ['* 2 RECENT', UnknownEvent::class, 'RECENT'],
    'status response' => ['* OK [ALERT] Maintenance soon', UnknownEvent::class, 'OK'],
    'extension response' => ['* X-NOTICE changed', UnknownEvent::class, 'X-NOTICE'],
]);

test('vanished events distinguish earlier changes from live changes', function (string $line, bool $earlier) {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed($line);
    $parser = new ImapParser(new ImapTokenizer($stream));

    $event = new MessagesVanished('INBOX', $parser->next());

    expect($event)->toBeInstanceOf(MessagesVanished::class);
    expect($event->uids()->all())->toBe([7, 8, 9, 12]);
    expect($event->earlier())->toBe($earlier);
})->with([
    'live removal' => ['* VANISHED 7:9,12', false],
    'checkpoint replay' => ['* VANISHED (EARLIER) 7:9,12', true],
]);

test('fake folders let selection callbacks query configured messages without supplying events', function () {
    $folder = new FakeFolder('INBOX', messages: new MessageCollection([
        $first = new FakeMessage(7, flags: ['\\Seen']),
        $second = new FakeMessage(8),
    ]));
    $received = [];
    $messages = [];

    $folder->events(function (EventInterface $event) use ($folder, &$received, &$messages) {
        $received[] = $event;

        if ($event instanceof FolderSelected) {
            $messages = $folder->messages()->orderByUid()->get()->all();
        }
    });

    expect($received)->toHaveCount(1);
    expect($received[0])->toBeInstanceOf(FolderSelected::class);
    expect($received[0]->folder())->toBe('INBOX');
    expect($messages)->toBe([$first, $second]);
});

test('fake folder event callbacks can query messages explicitly', function () {
    $message = new FakeMessage(7, flags: ['\\Seen']);
    $event = new MessagesExist('INBOX', new UntaggedResponse([
        new Atom('*'), new Number('10'), new Atom('EXISTS'),
    ]));
    $folder = (new FakeFolder('INBOX', messages: new MessageCollection([$message])))->setIdleEvents([$event]);
    $received = [];

    $folder->events(function (EventInterface $event) use ($folder, &$received) {
        if ($event instanceof MessagesExist) {
            $folder->messages()->get()->each(function ($message) use (&$received) {
                $received[] = $message;
            });

            return false;
        }
    });

    expect($received)->toBe([$message]);
    expect($event->folder())->toBe('INBOX');
    expect($event->type())->toBe('EXISTS');
    expect($event->count())->toBe(10);
});

test('fake folders deliver supplied events and stop when requested', function () {
    $folder = (new FakeFolder('INBOX'))->setIdleEvents([
        $first = new FolderSelected('INBOX', new Result(uidNext: 2)),
        new FolderSelected('INBOX', new Result(uidNext: 3)),
    ]);
    $received = [];

    $folder->events(function (EventInterface $event) use (&$received, $first) {
        $received[] = $event;

        return $event !== $first;
    });

    expect($received)->toHaveCount(2);
    expect($received[0])->toBeInstanceOf(FolderSelected::class);
    expect($received[1])->toBe($first);
});

test('fake folder idle delivers configured messages and supports query customization and stopping', function () {
    $folder = new FakeFolder('INBOX', messages: new MessageCollection([new FakeMessage(9), $first = new FakeMessage(7)]));
    $received = [];
    $queried = false;

    $folder->idle(function (MessageInterface $message) use (&$received) {
        $received[] = $message;

        return false;
    }, function (MessageQueryInterface $query) use (&$queried) {
        $queried = true;

        return $query->with(MessageData::flags());
    });

    expect($queried)->toBeTrue();
    expect($received)->toBe([$first]);
});
