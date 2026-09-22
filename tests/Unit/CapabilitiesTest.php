<?php

use DirectoryTree\ImapEngine\Capabilities;
use DirectoryTree\ImapEngine\Capability;

test('it creates readonly capabilities through a factory', function () {
    $capability = Capability::make('qresync', enabled: true);

    expect($capability->name())->toBe('QRESYNC');
    expect($capability->enabled())->toBeTrue();
    expect((new ReflectionClass($capability))->isReadOnly())->toBeTrue();
    expect((new ReflectionMethod(Capability::class, '__construct'))->isProtected())->toBeTrue();
});

test('it determines supported capabilities', function () {
    $capabilities = Capabilities::from(
        Capability::make('IMAP4rev1'),
        Capability::make('STARTTLS'),
        Capability::make('starttls'),
        Capability::make('AUTH=PLAIN'),
    );

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
    expect($capabilities->has('auth'))->toBeFalse();
    expect($capabilities->has('auth=plain'))->toBeTrue();
    expect($capabilities->has('START'))->toBeFalse();
});

test('it determines enabled capabilities', function () {
    $capabilities = Capabilities::from(
        Capability::make('QRESYNC'),
        Capability::make('AUTH=PLAIN'),
        Capability::make('auth=xoauth2', enabled: true),
    );

    expect($capabilities->enabled('QRESYNC'))->toBeFalse();
    expect($capabilities->enabled('AUTH'))->toBeFalse();
    expect($capabilities->enabled('AUTH=PLAIN'))->toBeFalse();
    expect($capabilities->enabled('AUTH=XOAUTH2'))->toBeTrue();
    expect((new ReflectionClass($capabilities))->isReadOnly())->toBeTrue();

    $items = $capabilities->items();
    $items['QRESYNC'] = Capability::make('QRESYNC', enabled: true);

    expect($capabilities->enabled('QRESYNC'))->toBeFalse();
});

test('it accepts only capability instances', function () {
    expect(fn () => Capabilities::from('QRESYNC'))->toThrow(TypeError::class);
});
