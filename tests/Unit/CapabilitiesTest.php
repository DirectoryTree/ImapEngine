<?php

use DirectoryTree\ImapEngine\Capabilities;

test('it determines supported capabilities', function () {
    $capabilities = Capabilities::from([
        'IMAP4rev1',
        'STARTTLS',
        'starttls',
        'AUTH=PLAIN',
    ]);

    expect($capabilities->all())->toBe([
        'IMAP4REV1',
        'STARTTLS',
        'AUTH=PLAIN',
    ]);
    expect($capabilities->supports('imap4rev1'))->toBeTrue();
    expect($capabilities->supports('AUTH'))->toBeTrue();
    expect($capabilities->supports('AUTH=PLAIN'))->toBeTrue();
    expect($capabilities->supports('AUTH=LOGIN'))->toBeFalse();
    expect($capabilities->supports('START'))->toBeFalse();
});

test('it determines enabled capabilities', function () {
    $capabilities = Capabilities::from([
        'QRESYNC',
        'AUTH=PLAIN',
        'AUTH=XOAUTH2',
    ]);

    expect($capabilities->enabled('QRESYNC'))->toBeFalse();

    $capabilities->enable('qresync', 'QRESYNC', 'AUTH=XOAUTH2');

    expect($capabilities->enabled('qresync'))->toBeTrue();
    expect($capabilities->enabled('AUTH'))->toBeFalse();
    expect($capabilities->enabled('AUTH=PLAIN'))->toBeFalse();
    expect($capabilities->enabled('AUTH=XOAUTH2'))->toBeTrue();
});
