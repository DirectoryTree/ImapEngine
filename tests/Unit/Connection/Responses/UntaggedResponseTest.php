<?php

use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
use DirectoryTree\ImapEngine\Connection\Tokens\Atom;

test('type', function () {
    $response = new UntaggedResponse([
        new Atom('*'),
        new Atom('b'),
        new Atom('c'),
    ]);

    expect($response->type())->toEqual(
        new Atom('b')
    );
});

test('data', function () {
    $response = new UntaggedResponse([
        new Atom('*'),
        new Atom('b'),
        new Atom('c'),
    ]);

    expect($response->data())->toEqual([
        new Atom('c'),
    ]);
});
