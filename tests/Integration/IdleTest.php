<?php

use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Connection\Streams\ImapStream;
use DirectoryTree\ImapEngine\Exceptions\ImapConnectionTimedOutException;
use DirectoryTree\ImapEngine\Idle\Events\EventInterface;
use DirectoryTree\ImapEngine\Idle\Events\FolderSelected;
use DirectoryTree\ImapEngine\Idle\Events\MessageExpunged;
use DirectoryTree\ImapEngine\Idle\Events\MessageFetched;
use DirectoryTree\ImapEngine\Idle\Events\MessagesExist;
use DirectoryTree\ImapEngine\Watch;
use Tests\Support\IdleAcknowledgementLogger;

test('idle receives typed changes from another connection and stops on callback', function (string $operation, string $eventClass) {
    $writer = mailbox();
    $folder = $writer->folders()->create(uniqid('events-'));

    try {
        $uid = $folder->messages()->append("Subject: Existing\r\n\r\nBody")->uid();

        $mutation = function () use ($operation, $folder, $uid) {
            match ($operation) {
                'append' => $folder->messages()->append("Subject: Arrival\r\n\r\nBody"),
                'flags' => $folder->messages()->findOrFail($uid)->markSeen(),
                'expunge' => $folder->messages()->findOrFail($uid)->delete(),
            };

            if ($operation === 'expunge') {
                $folder->expunge([$uid]);
            }
        };

        // Mutate only after the real server acknowledges IDLE. No responses are mocked.
        $logger = new IdleAcknowledgementLogger($mutation);

        $reader = mailbox(['timeout' => 5]);

        $reader->connect(new ImapConnection(new ImapStream, $logger));

        $intervals = 0;

        $timeout = function () use (&$intervals) {
            return ++$intervals === 1 ? 5 : 0;
        };

        $events = [];

        (new Watch($reader, $folder->path(), $timeout))->await(
            function (EventInterface $event) use (&$events, $eventClass) {
                $events[] = $event;

                return ! $event instanceof $eventClass;
            }
        );

        expect($logger->acknowledged)->toBeTrue();
        expect($intervals)->toBe(1);
        expect($events[0])->toBeInstanceOf(FolderSelected::class);

        $event = collect($events)->first(fn ($event) => $event instanceof $eventClass);

        expect($event)->toBeInstanceOf($eventClass);
        expect($event->folder())->toBe($folder->path());

        if ($event instanceof MessagesExist) {
            expect($event->count())->toBe(2);
        } elseif ($event instanceof MessageFetched) {
            expect($event->changes()->messages()[0]->flags())->toContain('\\Seen');
        } else {
            expect($event->sequenceNumber())->toBe(1);
        }

        expect($reader->connected())->toBeFalse();

        $reader->reconnect();

        expect($reader->folders()->findOrFail($folder->path())->messages()->count())->toBe(match ($operation) {
            'append' => 2,
            'flags' => 1,
            'expunge' => 0,
        });
    } finally {
        $folder->delete();
    }
})->with([
    'new message' => ['append', MessagesExist::class],
    'changed flags' => ['flags', MessageFetched::class],
    'expunged message' => ['expunge', MessageExpunged::class],
]);

test('idle can finish after a quiet timeout and reuse the same connection', function () {
    $mailbox = mailbox(['timeout' => 5]);
    $folder = $mailbox->folders()->create(uniqid('idle-timeout-'));

    try {
        $folder->select();

        $connection = $mailbox->connection();
        $session = $connection->idle();

        try {
            foreach ($session->responses(1) as $response) {
                // Drain unsolicited selection updates until the IDLE deadline.
            }
        } catch (ImapConnectionTimedOutException) {
            // A quiet socket may reach its read timeout before the loop deadline.
        }

        expect($session->active())->toBeTrue();

        $session->finish(5);

        expect($session->active())->toBeFalse();
        expect($mailbox->connected())->toBeTrue();
        expect($mailbox->connection())->toBe($connection);
        expect($folder->messages()->count())->toBe(0);
    } finally {
        $folder->delete();
    }
});

test('folder events stop renewing when the timeout callback returns zero', function () {
    $mailbox = mailbox(['timeout' => 5]);
    $folder = $mailbox->folders()->create(uniqid('events-timeout-'));

    try {
        $intervals = 0;
        $events = [];

        $folder->events(function (EventInterface $event) use (&$events) {
            $events[] = $event;
        }, function () use (&$intervals) {
            return ++$intervals === 1 ? 1 : 0;
        });

        expect($intervals)->toBe(2);
        expect(collect($events)->whereInstanceOf(FolderSelected::class))->toHaveCount(1);
        expect($folder->messages()->count())->toBe(0);
    } finally {
        $folder->delete();
    }
});
