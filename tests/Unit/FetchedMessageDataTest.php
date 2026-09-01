<?php

use DirectoryTree\ImapEngine\Connection\ImapParser;
use DirectoryTree\ImapEngine\Connection\ImapTokenizer;
use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
use DirectoryTree\ImapEngine\Connection\Streams\FakeStream;
use DirectoryTree\ImapEngine\FetchedMessageData;
use DirectoryTree\ImapEngine\Folder;
use DirectoryTree\ImapEngine\Mailbox;

test('it creates a message from fetch response data', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* 5 FETCH (UID 42 FLAGS (\\Seen) RFC822.SIZE 1024 BODY[HEADER] "Subject: Test" BODY[TEXT] "Hello world")',
    ]);

    $response = (new ImapParser(new ImapTokenizer($stream)))->next();

    expect($response)->toBeInstanceOf(UntaggedResponse::class);

    $data = FetchedMessageData::fromResponse($response);
    $message = $data->toMessage(new Folder(new Mailbox, 'INBOX'));

    expect($data->uid())->toBe(42)
        ->and($message->uid())->toBe(42)
        ->and($message->flags())->toBe(['\\Seen'])
        ->and($message->size())->toBe(1024)
        ->and($message->head())->toBe('Subject: Test')
        ->and($message->body())->toBe('Hello world');
});
