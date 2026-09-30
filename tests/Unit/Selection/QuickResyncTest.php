<?php

use DirectoryTree\ImapEngine\Selection\QuickResync;

test('it accepts the maximum uid validity value', function () {
    $option = new QuickResync(4294967295, 1);

    expect($option->toImap())->toBe('QRESYNC (4294967295 1)');
});

test('it rejects invalid uid validity values', function (int $uidValidity) {
    expect(fn () => new QuickResync($uidValidity, 1))
        ->toThrow(InvalidArgumentException::class);
})->with([0, 4294967296]);

test('it rejects invalid modification sequences', function (int $modSequence) {
    expect(fn () => new QuickResync(1, $modSequence))
        ->toThrow(InvalidArgumentException::class);
})->with([-1, 0]);
