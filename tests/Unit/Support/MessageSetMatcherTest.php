<?php

use DirectoryTree\ImapEngine\Support\MessageSetMatcher;

test('it matches individual numbers and ranges', function (int $number, bool $matches) {
    $matcher = new MessageSetMatcher('2,10:5,18:20');

    expect($matcher->contains($number))->toBe($matches);
})->with([
    [2, true],
    [5, true],
    [7, true],
    [10, true],
    [18, true],
    [20, true],
    [1, false],
    [4, false],
    [11, false],
    [21, false],
]);

test('it matches unordered overlapping and adjacent ranges', function (int $number, bool $matches) {
    $matcher = new MessageSetMatcher('20:18,1:4,10:5,3:8,17:15');

    expect($matcher->contains($number))->toBe($matches);
})->with([
    [1, true],
    [10, true],
    [15, true],
    [20, true],
    [11, false],
    [14, false],
    [21, false],
]);

test('it matches sets containing many compact ranges', function () {
    $ranges = [];

    for ($number = 1; $number <= 10000; $number += 4) {
        $ranges[] = $number.':'.($number + 1);
    }

    $matcher = new MessageSetMatcher(implode(',', $ranges));

    expect($matcher->contains(1))->toBeTrue();
    expect($matcher->contains(5001))->toBeTrue();
    expect($matcher->contains(9999))->toBeFalse();
    expect($matcher->contains(10001))->toBeFalse();
});

test('it preserves server-resolved sets without guessing their bounds', function (string $set) {
    $matcher = new MessageSetMatcher($set);

    expect($matcher->contains(1))->toBeTrue();
    expect($matcher->contains(4294967295))->toBeTrue();
})->with(['*', '999:*', '*:999', '$']);
