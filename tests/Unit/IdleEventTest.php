<?php

use DirectoryTree\ImapEngine\Connection\ImapParser;
use DirectoryTree\ImapEngine\Connection\ImapTokenizer;
use DirectoryTree\ImapEngine\Connection\Streams\FakeStream;
use DirectoryTree\ImapEngine\Idle\EventFactory;
use DirectoryTree\ImapEngine\Idle\Events\EventInterface;
use DirectoryTree\ImapEngine\Idle\Events\FolderSelected;
use DirectoryTree\ImapEngine\Idle\Events\MessageExpunged;
use DirectoryTree\ImapEngine\Idle\Events\MessageFetched;
use DirectoryTree\ImapEngine\Idle\Events\MessagesExist;
use DirectoryTree\ImapEngine\Idle\Events\MessagesVanished;
use DirectoryTree\ImapEngine\Idle\Events\UnknownEvent;
use DirectoryTree\ImapEngine\Selection\Result;
use DirectoryTree\ImapEngine\Testing\FakeFolder;

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

    $exists = EventFactory::fromResponse('INBOX', $parser->next());
    expect($exists)->toBeInstanceOf(MessagesExist::class);
    expect($exists->type())->toBe('EXISTS');
    expect($exists->count())->toBe(10);
    expect($exists->folder())->toBe('INBOX');

    $expunge = EventFactory::fromResponse('INBOX', $parser->next());
    expect($expunge)->toBeInstanceOf(MessageExpunged::class);
    expect($expunge->type())->toBe('EXPUNGE');
    expect($expunge->sequenceNumber())->toBe(3);

    $fetch = EventFactory::fromResponse('INBOX', $parser->next());
    expect($fetch)->toBeInstanceOf(MessageFetched::class);
    expect($fetch->changes()->messages()[0]->uid())->toBe(42);
    expect($fetch->sequenceNumber())->toBe(2);

    $vanished = EventFactory::fromResponse('INBOX', $parser->next());
    expect($vanished)->toBeInstanceOf(MessagesVanished::class);
    expect($vanished->type())->toBe('VANISHED');
    expect($vanished->uids())->toBe([7, 8, 9]);
    expect($vanished->earlier())->toBeTrue();

    $flags = EventFactory::fromResponse('INBOX', $parser->next());
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

test('idle event factory preserves response identity and type', function (string $line, string $class, string $type) {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed($line);
    $parser = new ImapParser(new ImapTokenizer($stream));
    $response = $parser->next();

    $event = EventFactory::fromResponse('Archive', $response);

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

    $event = EventFactory::fromResponse('INBOX', $parser->next());

    expect($event)->toBeInstanceOf(MessagesVanished::class);
    expect($event->uids())->toBe([7, 8, 9, 12]);
    expect($event->earlier())->toBe($earlier);
})->with([
    'live removal' => ['* VANISHED 7:9,12', false],
    'checkpoint replay' => ['* VANISHED (EARLIER) 7:9,12', true],
]);

test('fake folders deliver supplied events and stop when requested', function () {
    $folder = (new FakeFolder('INBOX'))->setIdleEvents([
        $first = new FolderSelected('INBOX', new Result(uidNext: 2)),
        new FolderSelected('INBOX', new Result(uidNext: 3)),
    ]);
    $received = [];

    $folder->idle(function (EventInterface $event) use (&$received, $first) {
        $received[] = $event;

        return $event !== $first;
    });

    expect($received)->toHaveCount(2);
    expect($received[0])->toBeInstanceOf(FolderSelected::class);
    expect($received[1])->toBe($first);
});
