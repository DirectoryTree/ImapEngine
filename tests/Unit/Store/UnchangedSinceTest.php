<?php

use DirectoryTree\ImapEngine\Store\UnchangedSince;

test('it accepts a zero modification sequence', function () {
    $modifier = new UnchangedSince(0);

    expect($modifier->toImap())->toBe('UNCHANGEDSINCE 0');
});

test('it rejects negative modification sequences', function () {
    expect(fn () => new UnchangedSince(-1))
        ->toThrow(InvalidArgumentException::class);
});
