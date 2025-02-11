<?php

use DirectoryTree\ImapEngine\Collections\FolderCollection;
use DirectoryTree\ImapEngine\Folder;

beforeEach(function () {
    mailbox()->folders()->get()->reject(
        fn (Folder $folder) => $folder->name() === 'INBOX'
    )->each->delete();
});

test('get', function () {
    $folders = mailbox()->folders()->get();

    expect($folders)->toBeInstanceOf(FolderCollection::class);
    expect($folders)->not->toBeEmpty();
});

test('find', function () {
    $folder = mailbox()->folders()->find('INBOX');

    expect($folder)->toBeInstanceOf(Folder::class);
    expect($folder)->name()->toBe('INBOX');
});

test('create', function () {
    $folder = mailbox()->folders()->create('foo');

    expect($folder)->toBeInstanceOf(Folder::class);
    expect($folder->path())->toBe('foo');
    expect($folder->name())->toBe('foo');
    expect($folder->delimiter())->toBe('.');
});

test('first or create', function () {
    $folders = mailbox()->folders();

    $first = $folders->firstOrCreate('foo');

    expect($first)->toBeInstanceOf(Folder::class);

    $second = $folders->firstOrCreate('foo');

    expect($second->is($first))->toBeTrue();

    expect($folders->get())->toHaveCount(2);
});

test('status', function () {
    $folder = mailbox()->inbox();

    expect($folder->status())->toHaveKeys([
        'MESSAGES',
        'RECENT',
        'UIDNEXT',
        'UIDVALIDITY',
        'UNSEEN',
    ]);
});

test('examine', function () {
    $folder = mailbox()->inbox();

    expect($folder->status())->toHaveKeys([
        'MESSAGES',
        'RECENT',
        'UIDNEXT',
        'UIDVALIDITY',
        'UNSEEN',
    ]);
});

test('delete', function () {
    $mailbox = mailbox();

    $folder = $mailbox->folders()->create('foo');

    $folder->delete();

    expect($mailbox->folders()->find('foo'))->toBeNull();
});
