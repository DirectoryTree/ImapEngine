<?php

use DirectoryTree\ImapEngine\Connection\ConnectionInterface;
use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Connection\Streams\FakeStream;
use DirectoryTree\ImapEngine\Exceptions\Exception;
use DirectoryTree\ImapEngine\Exceptions\ImapCapabilityException;
use DirectoryTree\ImapEngine\Exceptions\ImapCommandException;
use DirectoryTree\ImapEngine\Exceptions\ImapConnectionClosedException;
use DirectoryTree\ImapEngine\Folder;
use DirectoryTree\ImapEngine\Idle\Events\EventInterface;
use DirectoryTree\ImapEngine\Idle\Events\FolderSelected;
use DirectoryTree\ImapEngine\Idle\Events\MessagesExist;
use DirectoryTree\ImapEngine\Mailbox;
use DirectoryTree\ImapEngine\MessageInterface;
use DirectoryTree\ImapEngine\MessageQuery;

test('folder idle retrieves arrivals while events only delivers notifications', function (bool $retrieve) {
    $application = new FakeStream;
    $application->feed([
        '* OK Welcome', 'TAG1 OK LOGIN completed',
        '* CAPABILITY IMAP4rev1 IDLE', 'TAG2 OK CAPABILITY completed',
        '* OK [UIDVALIDITY 1]', '* OK [UIDNEXT 10]', 'TAG3 OK SELECT completed',
        '* SEARCH 7 9', 'TAG4 OK SEARCH completed',
        '* 2 FETCH (UID 7 FLAGS (\\Seen))', '* 3 FETCH (UID 9 FLAGS ())', 'TAG5 OK FETCH completed',
    ]);
    $watching = new FakeStream;
    $watching->feed([
        '* OK Welcome', 'TAG1 OK LOGIN completed',
        '* LIST () "/" "INBOX"', 'TAG2 OK LIST completed',
        '* 1 EXISTS', '* OK [UIDVALIDITY 1]', '* OK [UIDNEXT 7]', 'TAG3 OK SELECT completed',
        '+ idling', '* 3 EXISTS',
    ]);
    $mailbox = new class([$application, $watching]) extends Mailbox
    {
        public function __construct(protected array $streams)
        {
            parent::__construct();
        }

        public function connect(?ConnectionInterface $connection = null): void
        {
            parent::connect($connection ?? new ImapConnection(array_shift($this->streams)));
        }
    };
    $folder = new Folder($mailbox, 'INBOX');
    $received = [];
    $uids = [];

    try {
        if ($retrieve) {
            $folder->idle(function (MessageInterface $message) use (&$uids) {
                $uids[] = $message->uid();

                return $message->uid() !== 9;
            }, function (MessageQuery $query) {
                return $query->seen();
            });
        } else {
            $folder->events(function (EventInterface $event) use (&$received, $application) {
                $received[] = $event::class;
                $application->assertNotWritten('SELECT');
                $application->assertNotWritten('SEARCH');
                $application->assertNotWritten('FETCH');

                return ! $event instanceof MessagesExist;
            });
        }

        expect($received)->toBe($retrieve ? [] : [FolderSelected::class, MessagesExist::class]);
        expect($uids)->toBe($retrieve ? [7, 9] : []);
        expect($application->opened())->toBeTrue();
        expect($watching->opened())->toBeFalse();
        if ($retrieve) {
            $application->assertWritten('UID FETCH');
            $application->assertWritten('SEEN');
        } else {
            $application->assertNotWritten('UID FETCH');
        }
        $watching->assertNotWritten('FETCH');
        $watching->assertNotWritten('LOGOUT');
    } finally {
        $mailbox->connection()->disconnect();
        $mailbox->disconnect();
    }
})->with(['retrieve arrivals' => true, 'observe count only' => false]);

test('folder idle preserves or replaces the arrival cursor after reconnecting', function (int $validity, int $uidNext, int $expectedUid) {
    $application = new FakeStream;
    $application->feed([
        '* OK Welcome', 'TAG1 OK LOGIN completed',
        '* CAPABILITY IMAP4rev1 IDLE', 'TAG2 OK CAPABILITY completed',
        "* OK [UIDVALIDITY {$validity}]", 'TAG3 OK SELECT completed',
        "* SEARCH {$expectedUid}", 'TAG4 OK SEARCH completed',
        "* 2 FETCH (UID {$expectedUid} FLAGS ())", 'TAG5 OK FETCH completed',
    ]);
    $watching = new FakeStream;
    $watching->feed([
        '* OK Welcome', 'TAG1 OK LOGIN completed',
        '* LIST () "/" "INBOX"', 'TAG2 OK LIST completed',
        '* 1 EXISTS', '* OK [UIDVALIDITY 1]', '* OK [UIDNEXT 7]', 'TAG3 OK SELECT completed',
        '+ idling', '* BYE Restarting',
    ]);
    $reconnected = new FakeStream;
    $reconnected->feed([
        '* OK Welcome', 'TAG1 OK LOGIN completed',
        '* 2 EXISTS', "* OK [UIDVALIDITY {$validity}]", "* OK [UIDNEXT {$uidNext}]", 'TAG2 OK SELECT completed',
        '+ idling', '* 3 EXISTS',
    ]);
    $mailbox = new class([$application, $watching, $reconnected]) extends Mailbox
    {
        public function __construct(protected array $streams)
        {
            parent::__construct();
        }

        public function connect(?ConnectionInterface $connection = null): void
        {
            parent::connect($connection ?? new ImapConnection(array_shift($this->streams)));
        }
    };
    $folder = new Folder($mailbox, 'INBOX');
    $uids = [];
    $attempts = 0;

    try {
        $folder->idle(function (MessageInterface $message) use (&$uids) {
            $uids[] = $message->uid();

            return false;
        }, timeout: function () use (&$attempts) {
            return ++$attempts <= 2 ? 300 : false;
        });

        expect($uids)->toBe([$expectedUid]);
        $start = $validity === 1 ? 7 : $uidNext;
        $application->assertWritten("UID SEARCH UID {$start}:*");
        $watching->assertNotWritten('FETCH');
        $reconnected->assertNotWritten('FETCH');
    } finally {
        $mailbox->connection()->disconnect();
        $mailbox->disconnect();
    }
})->with([
    'same validity preserves the cursor' => [1, 10, 9],
    'changed validity replaces the cursor' => [2, 3, 3],
]);

test('folder polling propagates callback exceptions', function (string $exceptionClass) {
    $application = new FakeStream;
    $application->feed(['* OK Welcome', 'TAG1 OK LOGIN completed']);
    $polling = new FakeStream;
    $polling->feed([
        '* OK Welcome', 'TAG1 OK LOGIN completed',
        '* LIST () "/" "INBOX"', 'TAG2 OK LIST completed',
        '* OK [UIDNEXT 2]', 'TAG3 OK SELECT completed',
        '* LIST () "/" "INBOX"', 'TAG4 OK LIST completed',
        '* OK [UIDNEXT 3]', 'TAG5 OK SELECT completed',
        '* SEARCH 2', 'TAG6 OK SEARCH completed',
        '* 2 FETCH (UID 2 FLAGS ())', 'TAG7 OK FETCH completed',
    ]);
    $mailbox = new class([$application, $polling]) extends Mailbox
    {
        public function __construct(protected array $streams)
        {
            parent::__construct();
        }

        public function connect(?ConnectionInterface $connection = null): void
        {
            parent::connect($connection ?? new ImapConnection(array_shift($this->streams)));
        }
    };
    $mailbox->connect();
    $folder = new Folder($mailbox, 'INBOX');
    $exception = new $exceptionClass('Application callback failed');

    try {
        expect(fn () => $folder->poll(function () use ($exception) {
            throw $exception;
        }, frequency: 1))->toThrow($exception);
    } finally {
        $mailbox->connection()->disconnect();
        $mailbox->disconnect();
    }
})->with([
    RuntimeException::class,
    Exception::class,
    ImapConnectionClosedException::class,
]);

test('it examines a folder using the typed selection result', function () {
    $mailbox = Mailbox::make();
    $mailbox->connect(ImapConnection::fake([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* 3 EXISTS',
        '* OK [UIDVALIDITY 777] UIDs valid',
        'TAG2 OK EXAMINE completed',
    ]));

    $folder = new Folder($mailbox, 'INBOX');

    expect($folder->examine())->toBe([
        ['*', '3', 'EXISTS'],
        ['*', 'OK', ['UIDVALIDITY', '777'], 'UIDs', 'valid'],
    ]);
});

test('it properly decodes name from UTF-7', function () {
    $mailbox = Mailbox::make();

    // Create a folder with a UTF-7 encoded name.
    $folder = new Folder(
        mailbox: $mailbox,
        path: '[Gmail]/&BBoEPgRABDcEOAQ9BDA-',
        attributes: ['\\HasNoChildren'],
        delimiter: '/'
    );

    // The name should be decoded to UTF-8.
    expect($folder->name())->toBe('Корзина');

    // The path should remain as is (UTF-7 encoded).
    expect($folder->path())->toBe('[Gmail]/&BBoEPgRABDcEOAQ9BDA-');
});

test('it preserves existing UTF-8 characters in folder names', function () {
    $mailbox = Mailbox::make();

    // Create a folder with a name that already contains UTF-8 characters.
    $utf8FolderName = 'Привет';

    $folder = new Folder(
        mailbox: $mailbox,
        path: '[Gmail]/'.$utf8FolderName,
        attributes: ['\\HasNoChildren'],
        delimiter: '/'
    );

    // The name should remain unchanged
    expect($folder->name())->toBe($utf8FolderName);

    // Test with a mix of UTF-8 characters from different languages.
    $mixedUtf8FolderName = 'Привет_你好_こんにちは';

    $mixedFolder = new Folder(
        mailbox: $mailbox,
        path: '[Gmail]/'.$mixedUtf8FolderName,
        attributes: ['\\HasNoChildren'],
        delimiter: '/'
    );

    // The name should remain unchanged.
    expect($mixedFolder->name())->toBe($mixedUtf8FolderName);
});

test('it returns quota data for the mailbox', function () {
    $mailbox = Mailbox::make([
        'username' => 'foo',
        'password' => 'bar',
    ]);

    $mailbox->connect(ImapConnection::fake([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* LIST (\\HasNoChildren) "/" "INBOX"',
        'TAG2 OK LIST completed',
        '* CAPABILITY IMAP4rev1 LITERAL+ UIDPLUS SORT IDLE MOVE QUOTA',
        'TAG3 OK CAPABILITY completed',
        '* QUOTA "INBOX" (STORAGE 54 512)',
        '* QUOTA "INBOX" (MESSAGE 12 1024)',
        'TAG4 OK GETQUOTAROOT completed',
    ]));

    expect($mailbox->inbox()->quota())
        ->toBeArray()
        ->toMatchArray([
            'INBOX' => [
                'STORAGE' => [
                    'usage' => 54,
                    'limit' => 512,
                ],
                'MESSAGE' => [
                    'usage' => 12,
                    'limit' => 1024,
                ],
            ],
        ]);
});

test('it returns quota data for the mailbox when there are no quotas', function () {
    $mailbox = Mailbox::make([
        'username' => 'foo',
        'password' => 'bar',
    ]);

    $mailbox->connect(ImapConnection::fake([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* LIST (\\HasNoChildren) "/" "INBOX"',
        'TAG2 OK LIST completed',
        '* CAPABILITY IMAP4rev1 LITERAL+ UIDPLUS SORT IDLE MOVE QUOTA',
        'TAG3 OK CAPABILITY completed',
        'TAG4 OK GETQUOTAROOT completed',
    ]));

    expect($mailbox->inbox()->quota())->toBe([]);
});

test('it returns quota data for the mailbox when there are multiple resources', function () {
    $mailbox = Mailbox::make([
        'username' => 'foo',
        'password' => 'bar',
    ]);

    $mailbox->connect(ImapConnection::fake([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* LIST (\\HasNoChildren) "/" "INBOX"',
        'TAG2 OK LIST completed',
        '* CAPABILITY IMAP4rev1 LITERAL+ UIDPLUS SORT IDLE MOVE QUOTA',
        'TAG3 OK CAPABILITY completed',
        '* QUOTA "FOO" (STORAGE 54 512)',
        '* QUOTA "FOO" (MESSAGE 12 1024)',
        '* QUOTA "BAR" (STORAGE 10 1024)',
        '* QUOTA "BAR" (MESSAGE 5 1024)',
        'TAG4 OK GETQUOTAROOT completed',
    ]));

    expect($mailbox->inbox()->quota())
        ->toBeArray()
        ->toMatchArray([
            'FOO' => [
                'STORAGE' => [
                    'usage' => 54,
                    'limit' => 512,
                ],
                'MESSAGE' => [
                    'usage' => 12,
                    'limit' => 1024,
                ],
            ],
            'BAR' => [
                'STORAGE' => [
                    'usage' => 10,
                    'limit' => 1024,
                ],
                'MESSAGE' => [
                    'usage' => 5,
                    'limit' => 1024,
                ],
            ],
        ]);
});

test('it returns quota data for the mailbox when there are multiple resources in the same list data', function () {
    $mailbox = Mailbox::make([
        'username' => 'foo',
        'password' => 'bar',
    ]);

    $mailbox->connect(ImapConnection::fake([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* LIST (\\HasNoChildren) "/" "INBOX"',
        'TAG2 OK LIST completed',
        '* CAPABILITY IMAP4rev1 LITERAL+ UIDPLUS SORT IDLE MOVE QUOTA',
        'TAG3 OK CAPABILITY completed',
        '* QUOTA "FOO" (STORAGE 54 512 MESSAGE 12 1024)',
        '* QUOTA "BAR" (STORAGE 10 1024 MESSAGE 5 1024)',
        'TAG4 OK GETQUOTAROOT completed',
    ]));

    expect($mailbox->inbox()->quota())
        ->toBeArray()
        ->toMatchArray([
            'FOO' => [
                'STORAGE' => [
                    'usage' => 54,
                    'limit' => 512,
                ],
                'MESSAGE' => [
                    'usage' => 12,
                    'limit' => 1024,
                ],
            ],
            'BAR' => [
                'STORAGE' => [
                    'usage' => 10,
                    'limit' => 1024,
                ],
                'MESSAGE' => [
                    'usage' => 5,
                    'limit' => 1024,
                ],
            ],
        ]);
});

test('it throws an imap capability exception when inspecting quotas when the imap server does not support quotas', function () {
    $mailbox = Mailbox::make([
        'username' => 'foo',
        'password' => 'bar',
    ]);

    $mailbox->connect(ImapConnection::fake([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* LIST (\\HasNoChildren) "/" "INBOX"',
        'TAG2 OK LIST completed',
        '* CAPABILITY IMAP4rev1 LITERAL+ UIDPLUS SORT IDLE MOVE',
        'TAG3 OK CAPABILITY completed',
    ]));

    $mailbox->inbox()->quota();
})->throws(ImapCapabilityException::class);

test('examining a folder invalidates the previous writable selection', function (string $path) {
    $stream = new FakeStream;
    $stream->feed([
        '* OK Ready',
        'TAG1 OK Logged in',
        '* OK [UIDVALIDITY 100] Valid',
        'TAG2 OK [READ-WRITE] SELECT completed',
        '* OK [UIDVALIDITY 200] Valid',
        'TAG3 OK [READ-ONLY] EXAMINE completed',
        '* OK [UIDVALIDITY 300] Valid',
        'TAG4 OK [READ-WRITE] SELECT completed',
        '* SEARCH 7',
        'TAG5 OK SEARCH completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));
    $inbox = new Folder($mailbox, 'INBOX');
    $examined = new Folder($mailbox, $path);

    expect($inbox->select()->uidValidity())->toBe(100);
    $examined->examine();

    expect($mailbox->selected($inbox))->toBeFalse();
    expect($mailbox->selected($examined))->toBeFalse();
    expect($inbox->messages()->count())->toBe(1);
    expect($inbox->select()->uidValidity())->toBe(300);

    $stream->assertWritten('TAG3 EXAMINE "'.$path.'"');
    $stream->assertWritten('TAG4 SELECT "INBOX"');
    $stream->assertWritten('TAG5 UID SEARCH ALL');
})->with(['Archive', 'INBOX']);

test('failed examination still invalidates the previous selection', function () {
    $stream = new FakeStream;
    $stream->feed([
        '* OK Ready',
        'TAG1 OK Logged in',
        'TAG2 OK SELECT completed',
        'TAG3 NO Mailbox unavailable',
        '* OK [UIDVALIDITY 300] Valid',
        'TAG4 OK SELECT completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));
    $inbox = new Folder($mailbox, 'INBOX');
    $missing = new Folder($mailbox, 'Missing');

    $inbox->select();
    expect(fn () => $missing->examine())->toThrow(ImapCommandException::class);
    expect($mailbox->selected($inbox))->toBeFalse();
    expect($inbox->select()->uidValidity())->toBe(300);

    $stream->assertWritten('TAG4 SELECT "INBOX"');
});
