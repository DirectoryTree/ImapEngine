<?php

use DirectoryTree\ImapEngine\Collections\VanishedCollection;
use DirectoryTree\ImapEngine\FetchResult;
use DirectoryTree\ImapEngine\Support\SequenceSet;
use DirectoryTree\ImapEngine\Vanished;
use Illuminate\Support\LazyCollection;

test('vanished uids remain lazy across groups and preserve unique encounter order', function () {
    $result = new FetchResult(vanished: new VanishedCollection([
        new Vanished(new LazyCollection([3, 1, 3])),
        new Vanished(SequenceSet::parse('1:4294967295')),
    ]));

    $uids = $result->vanishedUids();

    expect($uids)->toBeInstanceOf(LazyCollection::class);
    expect($uids->take(5)->all())->toBe([3, 1, 2, 4, 5]);
    expect($uids->take(5)->all())->toBe([3, 1, 2, 4, 5]);
});
