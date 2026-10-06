<?php

use DirectoryTree\ImapEngine\Collections\MessageCollection;
use DirectoryTree\ImapEngine\Testing\FakeFolder;
use DirectoryTree\ImapEngine\Testing\FakeMailbox;
use DirectoryTree\ImapEngine\Testing\FakeMessage;
use DirectoryTree\ImapEngine\Testing\FakeMessageQuery;

test('it can be created with basic properties', function () {
    $folder = new FakeFolder(
        'INBOX',
        ['\\HasNoChildren'],
        new MessageCollection([new FakeMessage(1)]),
        '/',
        FakeMailbox::make()
    );

    expect($folder)->toBeInstanceOf(FakeFolder::class);
    expect($folder->path())->toBe('INBOX');
    expect($folder->attributes())->toBe(['\\HasNoChildren']);
    expect($folder->delimiter())->toBe('/');
});

test('it returns correct name from path', function () {
    $folder = new FakeFolder('INBOX/Sent');

    expect($folder->name())->toBe('Sent');

    $folder = new FakeFolder('INBOX');

    expect($folder->name())->toBe('INBOX');
});

test('it compares folders correctly', function () {
    $mailbox1 = FakeMailbox::make(['host' => 'imap.example.com', 'username' => 'user1']);
    $mailbox2 = FakeMailbox::make(['host' => 'imap.example.com', 'username' => 'user2']);

    $folder1 = new FakeFolder('INBOX', [], new MessageCollection([]), '/', $mailbox1);
    $folder2 = new FakeFolder('INBOX', [], new MessageCollection([]), '/', $mailbox1);
    $folder3 = new FakeFolder('Sent', [], new MessageCollection([]), '/', $mailbox1);
    $folder4 = new FakeFolder('INBOX', [], new MessageCollection([]), '/', $mailbox2);

    expect($folder1->is($folder2))->toBeTrue();
    expect($folder1->is($folder3))->toBeFalse(); // Different path
    expect($folder1->is($folder4))->toBeFalse(); // Different mailbox
});

test('it returns message query', function () {
    $folder = new FakeFolder('INBOX', [], new MessageCollection([new FakeMessage(1)]));

    $query = $folder->messages();

    expect($query)->toBeInstanceOf(FakeMessageQuery::class);
    expect($query->count())->toBe(1);
});

test('it can set path', function () {
    $folder = new FakeFolder('INBOX');

    $folder->setPath('Sent');

    expect($folder->path())->toBe('Sent');
});

test('it can set attributes', function () {
    $folder = new FakeFolder('INBOX');

    $folder->setAttributes(['\\Seen', '\\HasNoChildren']);

    expect($folder->attributes())->toBe(['\\Seen', '\\HasNoChildren']);
});

test('it can set mailbox', function () {
    $folder = new FakeFolder('INBOX');

    $mailbox = FakeMailbox::make(['host' => 'imap.example.com']);

    $folder->setMailbox($mailbox);

    expect($folder->mailbox())->toBe($mailbox);
});

test('it can set messages', function () {
    $folder = new FakeFolder('INBOX');

    $folder->setMessages(new MessageCollection([
        new FakeMessage(1),
        new FakeMessage(2),
    ]));

    expect($folder->messages()->count())->toBe(2);
});

test('it can set delimiter', function () {
    $folder = new FakeFolder('INBOX');

    $folder->setDelimiter('.');

    expect($folder->delimiter())->toBe('.');
});

test('it can query messages from a fake mailbox folder', function () {
    $folder = new FakeFolder('inbox', ['\\HasNoChildren'], new MessageCollection([
        new FakeMessage(1, [''], 'Message 1'),
        new FakeMessage(2, [''], 'Message 2'),
        new FakeMessage(3, ['\\Seen'], 'Message 3'),
    ]));

    // These should all have the same count because
    // no filtering should actually take place
    expect($folder->messages()->count())->toBe(3);
    expect($folder->messages()->where('Unseen')->count())->toBe(3);
    expect($folder->messages()->where('Seen')->count())->toBe(3);
});

test('it returns stub quota values', function () {
    $folder = new FakeFolder('INBOX');

    expect($folder->quota())->toBe([
        'INBOX' => [
            'STORAGE' => [
                'usage' => 0,
                'limit' => 0,
            ],
            'MESSAGE' => [
                'usage' => 0,
                'limit' => 0,
            ],
        ],
    ]);
});

test('fake examination invalidates the previous selection', function (string $path) {
    $mailbox = FakeMailbox::make();

    $inbox = new FakeFolder('INBOX', mailbox: $mailbox);
    $examined = new FakeFolder($path, mailbox: $mailbox);

    $inbox->select();
    expect($mailbox->selected($inbox))->toBeTrue();
    expect($examined->examine())->toBe([]);
    expect($mailbox->selected($inbox))->toBeFalse();
    expect($mailbox->selected($examined))->toBeFalse();

    $inbox->select();
    expect($mailbox->selected($inbox))->toBeTrue();
})->with(['Archive', 'INBOX']);

test('message collections remain independent of inputs snapshots and cloned folders', function () {
    $first = new FakeMessage(1);
    $second = new FakeMessage(2);
    $messages = new MessageCollection([$first]);
    $folder = new FakeFolder('INBOX', messages: $messages);
    $messages->pop();

    $snapshot = $folder->getMessages();
    $clone = clone $folder;
    $clone->addMessage($second);
    $snapshot->pop();

    expect($folder->getMessages()->all())->toBe([$first]);
    expect($clone->getMessages()->all())->toBe([$first, $second]);

    $replacement = new MessageCollection([$second]);
    $folder->setMessages($replacement);
    $replacement->pop();

    expect($folder->getMessages()->all())->toBe([$second]);
    expect($folder->nextUid())->toBe(3);
});
