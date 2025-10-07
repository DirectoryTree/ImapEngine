<?php

use DirectoryTree\ImapEngine\Enums\ImapFlag;
use DirectoryTree\ImapEngine\Testing\FakeMessage;

test('it can be created with basic properties', function () {
    $message = new FakeMessage(1, ['\\Seen'], 'Test message content');

    expect($message)->toBeInstanceOf(FakeMessage::class);
    expect($message->uid())->toBe(1);
    expect((string) $message)->toBe('Test message content');
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

    expect($message->date()->toDateTimeString())->toBe('2025-02-19 12:34:56');
    expect($message->subject())->toBe('Test Subject');
    expect($message->messageId())->toBe('unique-id@example.com');
    expect($message->from()->email())->toBe('john@example.com');
    expect($message->to())->toHaveCount(1);
    expect($message->to()[0]->email())->toBe('jane@example.com');
    expect((string) $message)->toBe($content);
});

test('it handles empty content', function () {
    $message = new FakeMessage(1);

    // Don't call methods that would trigger parse() on an empty message
    expect($message->uid())->toBe(1);
    expect((string) $message)->toBe('');
});

test('it can determine if two messages are the same', function () {
    $message1 = new FakeMessage(1, ['\\Seen'], 'Test content');
    $message2 = new FakeMessage(1, ['\\Seen'], 'Test content');
    $message3 = new FakeMessage(2, ['\\Seen'], 'Test content');
    $message4 = new FakeMessage(1, ['\\Draft'], 'Test content');
    $message5 = new FakeMessage(1, ['\\Seen'], 'Different content');

    // Same messages
    expect($message1->is($message2))->toBeTrue();

    // Different UID
    expect($message1->is($message3))->toBeFalse();

    // Different flags
    expect($message1->is($message4))->toBeFalse();

    // Different content
    expect($message1->is($message5))->toBeFalse();
});

test('it can add flags using flag method', function () {
    $message = new FakeMessage(1, [], 'Test content');

    expect($message->flags())->toBe([]);
    expect($message->isSeen())->toBeFalse();
    expect($message->isFlagged())->toBeFalse();

    // Add Seen flag
    $message->flag('\\Seen', '+');
    expect($message->flags())->toContain('\\Seen');
    expect($message->hasFlag(ImapFlag::Seen))->toBeTrue();
    expect($message->isSeen())->toBeTrue();

    // Add Flagged flag
    $message->flag('\\Flagged', '+');
    expect($message->flags())->toContain('\\Flagged');
    expect($message->hasFlag(ImapFlag::Flagged))->toBeTrue();
    expect($message->isFlagged())->toBeTrue();
    expect($message->flags())->toHaveCount(2);
});

test('it can remove flags using flag method', function () {
    $message = new FakeMessage(1, ['\\Seen', '\\Flagged'], 'Test content');

    expect($message->flags())->toContain('\\Seen');
    expect($message->flags())->toContain('\\Flagged');
    expect($message->hasFlag(ImapFlag::Seen))->toBeTrue();
    expect($message->hasFlag(ImapFlag::Flagged))->toBeTrue();
    expect($message->isSeen())->toBeTrue();
    expect($message->isFlagged())->toBeTrue();

    // Remove Seen flag
    $message->flag('\\Seen', '-');
    expect($message->flags())->not->toContain('\\Seen');
    expect($message->isSeen())->toBeFalse();
    expect($message->isFlagged())->toBeTrue();

    // Remove Flagged flag
    $message->flag('\\Flagged', '-');
    expect($message->flags())->not->toContain('\\Flagged');
    expect($message->isFlagged())->toBeFalse();
    expect($message->flags())->toBeEmpty();
});

test('it can get size when set', function () {
    $message = new FakeMessage(1, [], 'Test content', 1024);

    expect($message->size())->toBe(1024);
});

test('it returns size from contents when size is not set', function () {
    $message = new FakeMessage(1, [], 'Test content');

    expect($message->size())->toBe(strlen('Test content'));
});
