<?php

use DirectoryTree\ImapEngine\Connection\Responses\Data\ListData;
use DirectoryTree\ImapEngine\Connection\Tokens\Atom;

test('toString', function () {
    $response = new ListData([
        new Atom('a'),
        new Atom('b'),
        new Atom('c'),
    ]);

    expect((string) $response)->toEqual('(a b c)');
});
