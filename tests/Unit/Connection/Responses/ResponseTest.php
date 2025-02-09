<?php

use DirectoryTree\ImapEngine\Connection\Responses\Response;
use DirectoryTree\ImapEngine\Connection\Tokens\Atom;

test('tokens', function () {
    $response = new Response([
        new Atom('a'),
        new Atom('b'),
        new Atom('c'),
    ]);

    expect($response->tokens())->toEqual([
        new Atom('a'),
        new Atom('b'),
        new Atom('c'),
    ]);
});

test('toArray', function () {
    $response = new Response([
        new Atom('a'),
        new Atom('b'),
        new Atom('c'),
    ]);

    expect($response->toArray())->toEqual([
        'a',
        'b',
        'c',
    ]);
});

test('toString', function () {
    $response = new Response([
        new Atom('a'),
        new Atom('b'),
        new Atom('c'),
    ]);

    expect($response->__toString())->toEqual('a b c');
});
