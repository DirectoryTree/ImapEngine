<?php

use DirectoryTree\ImapEngine\Attachment;
use DirectoryTree\ImapEngine\BodyStructurePart;
use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Connection\Streams\FakeStream;
use DirectoryTree\ImapEngine\FetchedMessageData;
use DirectoryTree\ImapEngine\Folder;
use DirectoryTree\ImapEngine\Mailbox;
use DirectoryTree\ImapEngine\Message;
use DirectoryTree\ImapEngine\MessageData;
use DirectoryTree\ImapEngine\Support\LazyBodyPartStream;

test('partial items preserve response offsets and leave the original unchanged', function (): void {
    $item = MessageData::section('1.2');
    $partial = $item->partial(12, 64)->peek();

    expect($partial->key())->toBe('BODY[1.2]<12>')
        ->and($partial->toImap())->toBe('BODY.PEEK[1.2]<12.64>')
        ->and($item->toImap())->toBe('BODY[1.2]');
});

test('partial ranges reject invalid bounds', function (int $offset, int $length): void {
    MessageData::text()->partial($offset, $length);
})->with([[-1, 1], [0, 0], [0, -1]])->throws(InvalidArgumentException::class);

test('partial fetches parse short and empty responses without caching them as full parts', function (): void {
    $mailbox = new Mailbox;
    $wire = new FakeStream;
    $wire->feed([
        '* OK Welcome',
        'TAG1 OK Logged in',
        'TAG2 OK Selected',
        '* 1 FETCH (UID 7 BODY[2]<0> "abc")',
        'TAG3 OK Fetched',
        '* 1 FETCH (UID 7 BODY[2]<3> "")',
        'TAG4 OK Fetched',
        '* 1 FETCH (UID 7 BODY[2] "abcdef")',
        'TAG5 OK Fetched',
    ]);
    $mailbox->connect(new ImapConnection($wire));
    $message = new Message(new Folder($mailbox, 'INBOX'), new FetchedMessageData(['UID' => 7]));

    expect($message->bodyPart('2', offset: 0, length: 10))->toBe('abc')
        ->and($message->bodyPart('2', offset: 3, length: 10))->toBe('')
        ->and($message->bodyPart('2'))->toBe('abcdef');

    $wire->assertWritten('TAG3 UID FETCH 7 (BODY.PEEK[2]<0.10>)');
    $wire->assertWritten('TAG4 UID FETCH 7 (BODY.PEEK[2]<3.10>)');
});

test('missing body parts fail instead of producing a truncated attachment', function (): void {
    $message = new class extends Message
    {
        public function __construct() {}

        public function bodyPart(string $partNumber, bool $peek = true, int $offset = 0, ?int $length = null): ?string
        {
            return null;
        }
    };
    $stream = new LazyBodyPartStream($message, new BodyStructurePart('2', 'application', 'octet-stream'));

    $stream->read(1);
})->throws(RuntimeException::class, 'The body part is no longer available.');

test('attachment streams decode across chunk boundaries and preserve binary bytes', function (string $encoding, int $chunkSize): void {
    $contents = "Hello\0world\r\n\xff\r\n";
    $encoded = match ($encoding) {
        'base64' => chunk_split(base64_encode($contents), 4),
        'quoted-printable' => quoted_printable_encode($contents),
        default => $contents,
    };
    $message = new class($encoded) extends Message
    {
        public function __construct(
            /**
             * The transfer-encoded body part returned by partial fetches.
             */
            protected string $encoded,
        ) {}

        public function bodyPart(string $partNumber, bool $peek = true, int $offset = 0, ?int $length = null): ?string
        {
            return substr($this->encoded, $offset, $length);
        }
    };
    $stream = new LazyBodyPartStream($message, new BodyStructurePart('2', 'application', 'octet-stream', encoding: $encoding), $chunkSize);

    expect($stream->getSize())->toBeNull()
        ->and($stream->read(2))->toBe('He')
        ->and($stream->tell())->toBe(2)
        ->and($stream->getContents())->toBe(substr($contents, 2))
        ->and($stream->eof())->toBeTrue()
        ->and($stream->getSize())->toBe(strlen($contents));

    $stream->seek(-2, SEEK_END);
    expect($stream->read(2))->toBe("\r\n")
        ->and((string) $stream)->toBe($contents);
})->with(['base64', 'quoted-printable', '8bit'])->with([1, 3, 5, 64]);

test('a small read only fetches the required chunk and rewind reuses it', function (): void {
    $message = new class extends Message
    {
        /**
         * The body part fetch arguments recorded by the test message.
         */
        public array $requests = [];

        public function __construct() {}

        public function bodyPart(string $partNumber, bool $peek = true, int $offset = 0, ?int $length = null): ?string
        {
            $this->requests[] = [$partNumber, $peek, $offset, $length];

            return substr('abcdefgh', $offset, $length);
        }
    };
    $stream = new LazyBodyPartStream($message, new BodyStructurePart('2', 'application', 'octet-stream'), 4);

    expect($stream->read(1))->toBe('a');
    $stream->rewind();
    expect($stream->read(4))->toBe('abcd')
        ->and($message->requests)->toBe([['2', true, 0, 4]]);
    $stream->close();
    expect($stream->isReadable())->toBeFalse();
});

test('saving an attachment copies decoded chunks and returns its byte count', function (): void {
    $message = new class extends Message
    {
        public function __construct() {}

        public function bodyPart(string $partNumber, bool $peek = true, int $offset = 0, ?int $length = null): ?string
        {
            return substr('YWJjZA==', $offset, $length);
        }
    };
    $stream = new LazyBodyPartStream($message, new BodyStructurePart('2', 'application', 'octet-stream', encoding: 'base64'), 4);
    $attachment = new Attachment('file.bin', null, 'application/octet-stream', 'attachment', $stream);
    $path = tempnam(sys_get_temp_dir(), 'imap-partial-');

    try {
        expect($attachment->save($path))->toBe(4)
            ->and(file_get_contents($path))->toBe('abcd');
    } finally {
        unlink($path);
    }
});
