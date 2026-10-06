<?php

use DirectoryTree\ImapEngine\MessageData;
use DirectoryTree\ImapEngine\MessageData\FetchItemInterface;

test('it creates fixed message data items', function (FetchItemInterface $item, string $command) {
    expect($item->key())->toBe($command)
        ->and($item->toImap())->toBe($command);
})->with([
    [MessageData::flags(), 'FLAGS'],
    [MessageData::size(), 'RFC822.SIZE'],
    [MessageData::bodyStructure(), 'BODYSTRUCTURE'],
    [MessageData::modSequence(), 'MODSEQ'],
]);

test('it creates body section data items', function (FetchItemInterface $item, string $command) {
    expect($item->toImap())->toBe($command);
})->with([
    [MessageData::headers(), 'BODY[HEADER]'],
    [MessageData::text(), 'BODY[TEXT]'],
    [MessageData::section('1.2'), 'BODY[1.2]'],
]);

test('body section data items can be fetched without setting the seen flag', function (FetchItemInterface $item, string $command) {
    expect($item->peek()->toImap())->toBe($command);
})->with([
    [MessageData::headers(), 'BODY.PEEK[HEADER]'],
    [MessageData::text(), 'BODY.PEEK[TEXT]'],
    [MessageData::section('1.2'), 'BODY.PEEK[1.2]'],
]);

test('peeking does not modify the original body section data item', function () {
    $headers = MessageData::headers();

    expect($headers->peek())->not->toBe($headers)
        ->and($headers->toImap())->toBe('BODY[HEADER]');
});
