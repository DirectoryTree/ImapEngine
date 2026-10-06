<?php

use Carbon\Carbon;
use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Connection\Streams\FakeStream;
use DirectoryTree\ImapEngine\Exceptions\ImapCommandException;
use DirectoryTree\ImapEngine\Exceptions\ImapConnectionClosedException;
use DirectoryTree\ImapEngine\Exceptions\ImapConnectionTimedOutException;

test('idle completion uses the supplied timeout', function (?int $timeout, int $expected, bool $consume) {
    Carbon::setTestNow('2026-09-27 12:00:00');
    $stream = new class extends FakeStream
    {
        public array $timeouts = [];

        public function setTimeout(int $seconds): bool
        {
            $this->timeouts[] = $seconds;

            return true;
        }
    };
    $stream->feed(['* OK Welcome', '+ idling', '* 4 EXISTS', 'TAG1 OK IDLE completed']);
    $connection = new ImapConnection($stream);

    try {
        $connection->connect('localhost');
        $session = $connection->idle();

        if ($consume) {
            $responses = $session->responses(300);
            $responses->current();
            Carbon::setTestNow(Carbon::now()->addSeconds(301));
        }

        $stream->timeouts = [];
        $timeout === null ? $session->finish() : $session->finish($timeout);

        expect($stream->timeouts)->not->toBeEmpty();
        expect(array_unique($stream->timeouts))->toBe([$expected]);
        expect($session->active())->toBeFalse();
        $stream->assertWritten('DONE');
    } finally {
        $connection->disconnect();
        Carbon::setTestNow();
    }
})->with([
    'default before continuation' => [null, 30, false],
    'short before continuation' => [5, 5, false],
    'long before continuation' => [90, 90, false],
    'short after renewal deadline' => [5, 5, true],
    'long after renewal deadline' => [90, 90, true],
]);

test('an idle session owns the connection until it finishes', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed(['* OK Welcome', '+ idling', '* 4 EXISTS', 'TAG1 OK IDLE completed', 'TAG2 OK NOOP completed']);
    $connection = new ImapConnection($stream);
    $connection->connect('localhost');
    $session = $connection->idle();

    expect(fn () => $connection->noop())->toThrow(LogicException::class);
    expect(fn () => $connection->idle())->toThrow(LogicException::class);

    $responses = $session->responses();
    $responses->current();

    expect(fn () => $connection->noop())->toThrow(LogicException::class);
    expect($session->active())->toBeTrue();
    expect($session->finish())->toBeEmpty();
    expect($session->active())->toBeFalse();
    expect($connection->noop()->successful())->toBeTrue();
});

test('a session can finish before its responses are consumed', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed(['* OK Welcome', '* 4 EXISTS', '+ idling', '* 3 EXPUNGE', 'TAG1 OK IDLE completed']);
    $connection = new ImapConnection($stream);
    $connection->connect('localhost');
    $session = $connection->idle();

    expect($session->finish()->map(fn ($response) => (string) $response)->all())->toBe([
        '* 4 EXISTS', '* 3 EXPUNGE',
    ]);
    expect(iterator_to_array($session->responses()))->toBeEmpty();
    $stream->assertWritten('DONE');
});

test('a session rejects competing response consumers', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed(['* OK Welcome', '+ idling', '* 4 EXISTS']);
    $connection = new ImapConnection($stream);
    $connection->connect('localhost');
    $session = $connection->idle();
    $responses = $session->responses();
    $responses->current();

    expect(fn () => $session->responses()->current())->toThrow(LogicException::class);
    $connection->disconnect();
});

test('a disconnected session cannot consume responses from a new session', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed(['* OK Welcome', '+ idling', '* 4 EXISTS']);
    $connection = new ImapConnection($stream);
    $connection->connect('localhost');
    $session = $connection->idle();
    $responses = $session->responses();
    $responses->current();
    $connection->disconnect();

    $stream->feed(['* OK Welcome back', '+ idling', '* 5 EXISTS', 'TAG2 OK IDLE completed']);
    $connection->connect('localhost');
    $nextSession = $connection->idle();

    expect($session->active())->toBeFalse();
    expect($session->finish())->toBeEmpty();
    $responses->next();
    expect($responses->valid())->toBeFalse();
    expect($nextSession->finish()->map(fn ($response) => (string) $response)->all())->toBe(['* 5 EXISTS']);
});

test('a missing done acknowledgement invalidates the session and closes the connection', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed(['* OK Welcome', '+ idling', '* 4 EXISTS']);
    $connection = new ImapConnection($stream);
    $connection->connect('localhost');
    $session = $connection->idle();
    $responses = $session->responses();
    $responses->current();
    $stream->setMeta('timed_out', true);

    expect(fn () => $session->finish())->toThrow(ImapConnectionTimedOutException::class);
    expect($session->active())->toBeFalse();
    expect($connection->connected())->toBeFalse();
    expect($session->finish())->toBeEmpty();
    $stream->assertWritten('DONE');
});

test('idle preserves updates before continuation and while ending', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* OK Welcome',
        '* 4 EXISTS',
        '+ idling',
        '* 2 FETCH (FLAGS (\\Seen))',
        '* 3 EXPUNGE',
        '* VANISHED 8:9',
        'TAG1 OK IDLE completed',
        'TAG2 OK NOOP completed',
    ]);
    $connection = new ImapConnection($stream);
    $connection->connect('localhost');
    $session = $connection->idle();
    $idle = $session->responses(30);

    expect((string) $idle->current())->toBe('* 4 EXISTS');
    $idle->next();
    expect((string) $idle->current())->toBe('* 2 FETCH (FLAGS (\\Seen))');

    $pending = $session->finish();
    expect($pending->map(fn ($response) => (string) $response)->all())->toBe([
        '* 3 EXPUNGE', '* VANISHED 8:9',
    ]);
    expect($connection->noop()->successful())->toBeTrue();
    expect($session->finish())->toBeEmpty();
    $stream->assertWritten('DONE');
    $stream->assertWritten('TAG2 NOOP');
});

test('idle rejection is reported immediately', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed(['* OK Welcome', 'TAG1 NO IDLE unavailable']);
    $connection = new ImapConnection($stream);
    $connection->connect('localhost');

    $session = $connection->idle();

    expect(fn () => iterator_to_array($session->responses(30)))->toThrow(ImapCommandException::class);
    $stream->assertNotWritten('DONE');
});

test('idle reports server shutdown as a closed connection', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed(['* OK Welcome', '+ idling', '* BYE Server shutting down']);
    $connection = new ImapConnection($stream);
    $connection->connect('localhost');

    $session = $connection->idle();

    expect(fn () => iterator_to_array($session->responses(30)))->toThrow(ImapConnectionClosedException::class);
    expect($session->finish())->toBeEmpty();
});

test('idle responses retain distinct generator keys across continuation', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* OK Welcome', '* 4 EXISTS', '+ idling', '* 3 EXPUNGE', 'TAG1 OK IDLE completed',
    ]);
    $connection = new ImapConnection($stream);
    $connection->connect('localhost');

    $session = $connection->idle();
    $responses = iterator_to_array($session->responses(30));

    expect(array_map(fn ($response) => (string) $response, $responses))->toBe([
        '* 4 EXISTS', '* 3 EXPUNGE',
    ]);
});

test('idle can finish after a read timeout without losing queued changes', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed(['* OK Welcome', '+ idling', '* 4 EXISTS']);
    $connection = new ImapConnection($stream);
    $connection->connect('localhost');
    $session = $connection->idle();
    $idle = $session->responses(30);
    $idle->current();
    $stream->setMeta('timed_out', true);

    expect(fn () => $idle->next())->toThrow(ImapConnectionTimedOutException::class);

    $stream->setMeta('timed_out', false);
    $stream->feed(['* 3 EXPUNGE', 'TAG1 OK IDLE completed']);

    expect((string) $session->finish()->first())->toBe('* 3 EXPUNGE');
    $stream->assertWritten('DONE');
});

test('a timeout before continuation closes the ambiguous session', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed('* OK Welcome');
    $connection = new ImapConnection($stream);
    $connection->connect('localhost');
    $stream->setMeta('timed_out', true);

    $session = $connection->idle();

    expect(fn () => iterator_to_array($session->responses(30)))->toThrow(ImapConnectionClosedException::class);
    expect($connection->connected())->toBeFalse();
    expect($session->finish())->toBeEmpty();
    $stream->assertNotWritten('DONE');
});

test('failed idle completion is surfaced by finish', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed(['* OK Welcome', '+ idling', '* 4 EXISTS', 'TAG1 NO IDLE failed']);
    $connection = new ImapConnection($stream);
    $connection->connect('localhost');
    $session = $connection->idle();
    $idle = $session->responses(30);
    $idle->current();

    expect(fn () => $session->finish())->toThrow(ImapCommandException::class);
    expect($session->finish())->toBeEmpty();
});

test('finish preserves pre-continuation updates not yet consumed by the generator', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* OK Welcome', '* 4 EXISTS', '* 2 EXPUNGE', '+ idling',
        '* 3 EXISTS', 'TAG1 OK IDLE completed',
    ]);
    $connection = new ImapConnection($stream);
    $connection->connect('localhost');
    $session = $connection->idle();
    $idle = $session->responses(30);

    expect((string) $idle->current())->toBe('* 4 EXISTS');
    expect($session->finish()->map(fn ($response) => (string) $response)->all())->toBe([
        '* 2 EXPUNGE', '* 3 EXISTS',
    ]);
    $idle->next();
    expect($idle->valid())->toBeFalse();
});
