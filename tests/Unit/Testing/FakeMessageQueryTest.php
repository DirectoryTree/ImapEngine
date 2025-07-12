<?php

use DirectoryTree\ImapEngine\Collections\MessageCollection;
use DirectoryTree\ImapEngine\Testing\FakeFolder;
use DirectoryTree\ImapEngine\Testing\FakeMessage;
use DirectoryTree\ImapEngine\Testing\FakeMessageQuery;
use Illuminate\Support\ItemNotFoundException;

test('it can be created with basic properties', function () {
    $folder = new FakeFolder('INBOX', messages: [
        new FakeMessage(1),
        new FakeMessage(2),
    ]);

    $query = new FakeMessageQuery($folder);

    expect($query)->toBeInstanceOf(FakeMessageQuery::class);
});

test('it returns message collection', function () {
    $folder = new FakeFolder('INBOX', messages: [
        new FakeMessage(1),
        new FakeMessage(2),
    ]);

    $query = new FakeMessageQuery($folder);
    $collection = $query->get();

    expect($collection)->toBeInstanceOf(MessageCollection::class);
    expect($collection)->toHaveCount(2);
});

test('it counts messages correctly', function () {
    $folder = new FakeFolder('INBOX', messages: [
        new FakeMessage(1),
        new FakeMessage(2),
        new FakeMessage(3),
    ]);

    $query = new FakeMessageQuery($folder);

    expect($query->count())->toBe(3);
});

test('it returns first message', function () {
    $folder = new FakeFolder('INBOX', messages: [
        new FakeMessage(1),
        new FakeMessage(2),
    ]);

    $query = new FakeMessageQuery($folder);

    $first = $query->first();

    expect($first)->toBeInstanceOf(FakeMessage::class);
    expect($first->uid())->toBe(1);
});

test('it returns null when no messages exist for first()', function () {
    $folder = new FakeFolder('INBOX');
    $query = new FakeMessageQuery($folder);

    expect($query->first())->toBeNull();
});

test('it throws exception when no messages exist for firstOrFail()', function () {
    $folder = new FakeFolder('INBOX');
    $query = new FakeMessageQuery($folder);

    $query->firstOrFail();
})->throws(ItemNotFoundException::class);

test('it auto-increments uid when appending messages', function () {
    $folder = new FakeFolder('INBOX');
    $query = new FakeMessageQuery($folder);

    $uid1 = $query->append('First message');
    expect($uid1)->toBe(1);

    $uid2 = $query->append('Second message');
    expect($uid2)->toBe(2);

    $uid3 = $query->append('Third message');
    expect($uid3)->toBe(3);

    expect($query->count())->toBe(3);
});

test('it continues auto-incrementing from last message uid', function () {
    $folder = new FakeFolder('INBOX', messages: [
        new FakeMessage(5),
    ]);

    $query = new FakeMessageQuery($folder);

    $uid = $query->append('New message');
    expect($uid)->toBe(6);
});

test('it can find message by uid', function () {
    $folder = new FakeFolder('INBOX', messages: [
        new FakeMessage(1),
        new FakeMessage(2),
        new FakeMessage(3),
    ]);

    $query = new FakeMessageQuery($folder);

    $message = $query->find(2);

    expect($message)->toBeInstanceOf(FakeMessage::class);
    expect($message->uid())->toBe(2);
});

test('it returns null when message not found', function () {
    $folder = new FakeFolder('INBOX', [
        new FakeMessage(1),
        new FakeMessage(2),
    ]);

    $query = new FakeMessageQuery($folder);

    expect($query->find(999))->toBeNull();
});

test('it throws exception when message not found with findOrFail', function () {
    $folder = new FakeFolder('INBOX', messages: [
        new FakeMessage(1),
        new FakeMessage(2),
    ]);

    $query = new FakeMessageQuery($folder);

    $query->findOrFail(999);
})->throws(ItemNotFoundException::class);

test('it can destroy messages by uid', function () {
    $folder = new FakeFolder('INBOX', messages: [
        new FakeMessage(1),
        new FakeMessage(2),
        new FakeMessage(3),
    ]);

    $query = new FakeMessageQuery($folder);

    expect($query->count())->toBe(3);

    $query->destroy(2);

    expect($query->count())->toBe(2);
    expect($query->find(2))->toBeNull();
    expect($query->find(1))->not->toBeNull();
    expect($query->find(3))->not->toBeNull();
});

test('it can destroy multiple messages', function () {
    $folder = new FakeFolder('INBOX', messages: [
        new FakeMessage(1),
        new FakeMessage(2),
        new FakeMessage(3),
        new FakeMessage(4),
    ]);

    $query = new FakeMessageQuery($folder);

    expect($query->count())->toBe(4);

    $query->destroy([1, 3]);

    expect($query->count())->toBe(2);
    expect($query->find(1))->toBeNull();
    expect($query->find(3))->toBeNull();
    expect($query->find(2))->not->toBeNull();
    expect($query->find(4))->not->toBeNull();
});

test('each breaks when callback returns false', function () {
    $folder = new FakeFolder('INBOX', messages: [
        new FakeMessage(1, [], 'Message 1'),
        new FakeMessage(2, [], 'Message 2'),
        new FakeMessage(3, [], 'Message 3'),
        new FakeMessage(4, [], 'Message 4'),
        new FakeMessage(5, [], 'Message 5'),
    ]);

    $query = new FakeMessageQuery($folder);
    $processedUids = [];

    $query->each(function ($message) use (&$processedUids) {
        $processedUids[] = $message->uid();

        // Break after processing the third message
        if ($message->uid() === 3) {
            return false;
        }
    }, 2); // Use chunk size of 2

    // Should process messages 1, 2, and 3, then break
    expect($processedUids)->toBe([1, 2, 3]);
});

test('chunk breaks when callback returns false', function () {
    $folder = new FakeFolder('INBOX', messages: [
        new FakeMessage(1, [], 'Message 1'),
        new FakeMessage(2, [], 'Message 2'),
        new FakeMessage(3, [], 'Message 3'),
        new FakeMessage(4, [], 'Message 4'),
        new FakeMessage(5, [], 'Message 5'),
    ]);

    $query = new FakeMessageQuery($folder);
    $processedChunks = [];

    $query->chunk(function ($messages, $page) use (&$processedChunks) {
        $processedChunks[] = $page;

        // Break after processing the second chunk
        if ($page === 2) {
            return false;
        }
    }, 2); // Use chunk size of 2

    // Should process chunks 1 and 2, then break (chunk 3 should not be processed)
    expect($processedChunks)->toBe([1, 2]);
});

test('each processes all messages when callback never returns false', function () {
    $folder = new FakeFolder('INBOX', messages: [
        new FakeMessage(1, [], 'Message 1'),
        new FakeMessage(2, [], 'Message 2'),
        new FakeMessage(3, [], 'Message 3'),
    ]);

    $query = new FakeMessageQuery($folder);
    $processedUids = [];

    $query->each(function ($message) use (&$processedUids) {
        $processedUids[] = $message->uid();
        // Never return false
    });

    // Should process all messages
    expect($processedUids)->toBe([1, 2, 3]);
});

test('chunk processes all chunks when callback never returns false', function () {
    $folder = new FakeFolder('INBOX', messages: [
        new FakeMessage(1, [], 'Message 1'),
        new FakeMessage(2, [], 'Message 2'),
        new FakeMessage(3, [], 'Message 3'),
        new FakeMessage(4, [], 'Message 4'),
        new FakeMessage(5, [], 'Message 5'),
    ]);

    $query = new FakeMessageQuery($folder);
    $processedChunks = [];

    $query->chunk(function ($messages, $page) use (&$processedChunks) {
        $processedChunks[] = $page;
        // Never return false
    }, 2); // Use chunk size of 2

    // Should process all chunks (1, 2, 3)
    expect($processedChunks)->toBe([1, 2, 3]);
});
