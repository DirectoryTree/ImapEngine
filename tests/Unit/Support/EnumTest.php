<?php

use DirectoryTree\ImapEngine\Enums\ImapFlag;
use DirectoryTree\ImapEngine\Support\Enum;

test('enums returns value for a single backed enum', function () {
    $result = Enum::values(ImapFlag::Seen);

    expect($result)->toBe('\Seen');
});

test('enums returns an array of enum values for an array of backed enums', function () {
    $result = Enum::values([ImapFlag::Seen, ImapFlag::Draft]);

    expect($result)->toBeArray();
    expect($result)->toEqual(['\Seen', '\Draft']);
});

test('enums returns the string when a string is provided', function () {
    $input = 'example string';

    $result = Enum::values($input);

    expect($result)->toBe($input);
});

test('enums handles nested arrays containing backed enums and strings', function () {
    $input = [
        [ImapFlag::Seen, 'nested string'],
        ImapFlag::Draft,
        'another string',
    ];

    $expected = [
        ['\Seen', 'nested string'],
        '\Draft',
        'another string',
    ];

    $result = Enum::values($input);

    expect($result)->toEqual($expected);
});
