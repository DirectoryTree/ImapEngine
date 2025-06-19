<?php

use DirectoryTree\ImapEngine\Testing\FakeFolder;
use DirectoryTree\ImapEngine\Testing\FakeMailbox;
use DirectoryTree\ImapEngine\Testing\FakeMessage;
use DirectoryTree\ImapEngine\Testing\FakeMessageQuery;

test('it can be created with basic properties', function () {
    $folder = new FakeFolder(
        'INBOX',
        ['\\HasNoChildren'],
        [new FakeMessage(1)],
        '/',
        new FakeMailbox
    );

    expect($folder)->toBeInstanceOf(FakeFolder::class);
    expect($folder->path())->toBe('INBOX');
    expect($folder->flags())->toBe(['\\HasNoChildren']);
    expect($folder->delimiter())->toBe('/');
});

test('it returns correct name from path', function () {
    $folder = new FakeFolder('INBOX/Sent');

    expect($folder->name())->toBe('Sent');

    $folder = new FakeFolder('INBOX');

    expect($folder->name())->toBe('INBOX');
});

test('it compares folders correctly', function () {
    $mailbox1 = new FakeMailbox(['host' => 'imap.example.com', 'username' => 'user1']);
    $mailbox2 = new FakeMailbox(['host' => 'imap.example.com', 'username' => 'user2']);

    $folder1 = new FakeFolder('INBOX', [], [], '/', $mailbox1);
    $folder2 = new FakeFolder('INBOX', [], [], '/', $mailbox1);
    $folder3 = new FakeFolder('Sent', [], [], '/', $mailbox1);
    $folder4 = new FakeFolder('INBOX', [], [], '/', $mailbox2);

    expect($folder1->is($folder2))->toBeTrue();
    expect($folder1->is($folder3))->toBeFalse(); // Different path
    expect($folder1->is($folder4))->toBeFalse(); // Different mailbox
});

test('it returns message query', function () {
    $folder = new FakeFolder('INBOX', [], [new FakeMessage(1)]);

    $query = $folder->messages();

    expect($query)->toBeInstanceOf(FakeMessageQuery::class);
    expect($query->count())->toBe(1);
});

test('it can set path', function () {
    $folder = new FakeFolder('INBOX');

    $folder->setPath('Sent');

    expect($folder->path())->toBe('Sent');
});

test('it can set flags', function () {
    $folder = new FakeFolder('INBOX');

    $folder->setFlags(['\\Seen', '\\HasNoChildren']);

    expect($folder->flags())->toBe(['\\Seen', '\\HasNoChildren']);
});

test('it can set mailbox', function () {
    $folder = new FakeFolder('INBOX');
    $mailbox = new FakeMailbox(['host' => 'imap.example.com']);

    $folder->setMailbox($mailbox);

    expect($folder->mailbox())->toBe($mailbox);
});

test('it can set messages', function () {
    $folder = new FakeFolder('INBOX');
    $messages = [new FakeMessage(1), new FakeMessage(2)];

    $folder->setMessages($messages);

    expect($folder->messages()->count())->toBe(2);
});

test('it can set delimiter', function () {
    $folder = new FakeFolder('INBOX');

    $folder->setDelimiter('.');

    expect($folder->delimiter())->toBe('.');
});

it('can query messages from a fake mailbox folder', function () {
    $message1 = new FakeMessage(1, [''], 'Message 1');
    $message2 = new FakeMessage(2, [''], 'Message 2');
    $message3 = new FakeMessage(3, ['\\Seen'], 'Message 3');

    $folder = new FakeFolder('inbox', ['\\HasNoChildren'], [$message1, $message2, $message3]);

    expect($folder->messages()->count())->toBe(3);

    expect($folder->messages()->where('Unseen')->count())->toBe(2);
    expect($folder->messages()->where('Seen')->count())->toBe(1);
});
