<?php

use DirectoryTree\ImapEngine\Testing\FakeMessage;

test('it can be created with basic properties', function () {
    $message = new FakeMessage(1, ['\\Seen'], 'Test message content');

    expect($message)->toBeInstanceOf(FakeMessage::class);
    expect($message->uid())->toBe(1);
    expect($message->__toString())->toBe('Test message content');
});

test('it returns uid correctly', function () {
    $message = new FakeMessage(123);

    expect($message->uid())->toBe(123);
});

test('it can be cast to string', function () {
    $message = new FakeMessage(1, [], 'Hello world');

    expect((string) $message)->toBe('Hello world');
});

test('it can store message content', function () {
    $content = <<<'EOT'
From: "John Doe" <john@example.com>
To: "Jane Smith" <jane@example.com>
Subject: Test Subject
Date: Wed, 19 Feb 2025 12:34:56 -0500
Message-ID: <unique-id@example.com>
MIME-Version: 1.0
Content-Type: text/plain; charset="UTF-8"

Hello World
EOT;

    $message = new FakeMessage(1, [], $content);

    // We'll just test that the content is stored correctly
    // The actual parsing is handled by the HasParsedMessage trait
    expect($message->__toString())->toBe($content);
});

test('it handles empty content', function () {
    $message = new FakeMessage(1);

    // Don't call methods that would trigger parse() on an empty message
    expect($message->uid())->toBe(1);
    expect($message->__toString())->toBe('');
});
