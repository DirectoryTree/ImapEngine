<?php

use DirectoryTree\ImapEngine\Connection\Responses\TaggedResponse;
use DirectoryTree\ImapEngine\Connection\Tokens\Atom;

test('tag', function () {
    $response = new TaggedResponse([
        new Atom('TAG1'),
        new Atom('b'),
        new Atom('c'),
    ]);

    expect($response->tag())->toEqual(
        new Atom('TAG1')
    );
});

test('status', function () {
    $response = new TaggedResponse([
        new Atom('TAG1'),
        new Atom('OK'),
        new Atom('c'),
    ]);

    expect($response->status())->toEqual(new Atom('OK'));
});

test('data', function () {
    $response = new TaggedResponse([
        new Atom('TAG1'),
        new Atom('OK'),
        new Atom('c'),
    ]);

    expect($response->data())->toEqual([
        new Atom('c'),
    ]);
});

test('successful', function () {
    $response = new TaggedResponse([
        new Atom('TAG1'),
        new Atom('OK'),
    ]);

    expect($response->successful())->toBeTrue();

    $response = new TaggedResponse([
        new Atom('TAG1'),
        new Atom('NO'),
    ]);

    expect($response->successful())->toBeFalse();

    $response = new TaggedResponse([
        new Atom('TAG1'),
        new Atom('BAD'),
    ]);

    expect($response->successful())->toBeFalse();
});

test('failed', function () {
    $response = new TaggedResponse([
        new Atom('TAG1'),
        new Atom('OK'),
    ]);

    expect($response->failed())->toBeFalse();

    $response = new TaggedResponse([
        new Atom('TAG1'),
        new Atom('NO'),
    ]);

    expect($response->failed())->toBeTrue();

    $response = new TaggedResponse([
        new Atom('TAG1'),
        new Atom('BAD'),
    ]);

    expect($response->failed())->toBeTrue();
});
