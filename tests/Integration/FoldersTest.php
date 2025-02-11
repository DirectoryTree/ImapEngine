<?php

use DirectoryTree\ImapEngine\Collections\FolderCollection;
use DirectoryTree\ImapEngine\Folder;
use Illuminate\Support\Str;

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
    $folder = mailbox()->folders()->create(
        $id = (string) Str::uuid()
    );

    expect($folder)->toBeInstanceOf(Folder::class);
    expect($folder->path())->toBe($id);
    expect($folder->name())->toBe($id);
    expect($folder->delimiter())->toBe('.');
    expect($folder->status())->toHaveKeys([
        'MESSAGES',
        'RECENT',
        'UIDNEXT',
        'UIDVALIDITY',
        'UNSEEN',
    ]);
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

    $folder = $mailbox->folders()->create(
        $id = (string) Str::uuid()
    );

    $folder->delete();

    expect($mailbox->folders()->find($id))->toBeNull();
});
