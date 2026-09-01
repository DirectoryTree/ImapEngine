<?php

use DirectoryTree\ImapEngine\Enums\ImapFetchItem;

test('it converts fetch items into IMAP commands', function (ImapFetchItem $item, string $command) {
    expect($item->command())->toBe($command);
})->with([
    [ImapFetchItem::Flags, 'FLAGS'],
    [ImapFetchItem::Size, 'RFC822.SIZE'],
    [ImapFetchItem::Headers, 'BODY.PEEK[HEADER]'],
    [ImapFetchItem::Body, 'BODY.PEEK[TEXT]'],
    [ImapFetchItem::BodyStructure, 'BODYSTRUCTURE'],
]);

test('body fetch items can mark messages as read', function (ImapFetchItem $item, string $command) {
    expect($item->command(leaveUnread: false))->toBe($command);
})->with([
    [ImapFetchItem::Headers, 'BODY[HEADER]'],
    [ImapFetchItem::Body, 'BODY[TEXT]'],
]);
