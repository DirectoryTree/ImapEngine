<?php

use DirectoryTree\ImapEngine\Collections\FetchedResponseCollection;
use DirectoryTree\ImapEngine\Collections\ResponseCollection;
use DirectoryTree\ImapEngine\Connection\ImapParser;
use DirectoryTree\ImapEngine\Connection\ImapTokenizer;
use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
use DirectoryTree\ImapEngine\Connection\Streams\FakeStream;
use DirectoryTree\ImapEngine\FetchedResponse;

test('response collections filter raw fetch responses while fetched responses retain their sequence numbers', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* 4 EXISTS',
        '* 2 FETCH (UID 7 FLAGS (\Seen))',
        '* 3 FETCH (UID 9 FLAGS (\Flagged))',
        'TAG1 OK Completed',
    ]);

    $parser = new ImapParser(new ImapTokenizer($stream));
    $responses = new ResponseCollection([
        $parser->next(),
        $parser->next(),
        $parser->next(),
        $parser->next(),
    ]);

    $fetches = $responses->fetches();

    expect($fetches)->toBeInstanceOf(ResponseCollection::class);
    expect($fetches)->toHaveCount(2);
    expect($fetches[1])->toBeInstanceOf(UntaggedResponse::class);
    expect($fetches[1])->toBe($responses[1]);
    expect($fetches[2])->toBe($responses[2]);

    $parsed = FetchedResponse::collect($responses);
    $first = $parsed[0];
    $second = $parsed[1];

    expect($parsed)->toBeInstanceOf(FetchedResponseCollection::class);
    expect($parsed)->toHaveCount(2);
    expect($first->sequenceNumber())->toBe(2);
    expect($first->data()->uid())->toBe(7);
    expect($first->response())->toBe($responses[1]);
    expect($second->sequenceNumber())->toBe(3);
    expect($second->data()->flags())->toBe(['\Flagged']);
    expect($responses)->toHaveCount(4);
});
