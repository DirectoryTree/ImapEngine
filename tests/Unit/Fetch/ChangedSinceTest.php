<?php

use DirectoryTree\ImapEngine\Enums\ImapIdentifier;
use DirectoryTree\ImapEngine\Fetch\ChangedSince;

test('it creates a fetch modifier for either identifier type', function (ImapIdentifier $identifier) {
    $modifier = new ChangedSince(42);

    expect($modifier->toImap($identifier))->toBe('CHANGEDSINCE 42');
})->with(ImapIdentifier::cases());

test('it includes vanished when using uid fetch', function () {
    $modifier = new ChangedSince(42, vanished: true);

    expect($modifier->toImap(ImapIdentifier::Uid))->toBe('CHANGEDSINCE 42 VANISHED');
});

test('it rejects vanished when using message number fetch', function () {
    $modifier = new ChangedSince(42, vanished: true);

    expect(fn () => $modifier->toImap(ImapIdentifier::MessageNumber))
        ->toThrow(InvalidArgumentException::class);
});
