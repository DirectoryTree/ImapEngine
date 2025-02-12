<?php

use DirectoryTree\ImapEngine\Connection\ImapQueryBuilder;
use DirectoryTree\ImapEngine\Folder;
use DirectoryTree\ImapEngine\Mailbox;
use DirectoryTree\ImapEngine\MessageQuery;

function query(): MessageQuery
{
    return new MessageQuery(
        new Folder(new Mailbox, 'test'),
        new ImapQueryBuilder
    );
}

test('passthru', function () {
    $query = query();

    expect($query->toImap())->toBe('');
    expect($query->isEmpty())->toBeTrue();
});

test('where', function () {
    $query = query()
        ->where('subject', 'hello')
        ->toImap();

    expect($query)->toBe('SUBJECT "hello"');
});
