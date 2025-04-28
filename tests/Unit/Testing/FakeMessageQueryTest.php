<?php

use DirectoryTree\ImapEngine\Collections\MessageCollection;
use DirectoryTree\ImapEngine\Testing\FakeFolder;
use DirectoryTree\ImapEngine\Testing\FakeMessage;
use DirectoryTree\ImapEngine\Testing\FakeMessageQuery;
use Illuminate\Support\ItemNotFoundException;

test('it can be created with basic properties', function () {
    $folder = new FakeFolder('INBOX');
    $messages = [new FakeMessage(1), new FakeMessage(2)];

    $query = new FakeMessageQuery($folder, $messages);

    expect($query)->toBeInstanceOf(FakeMessageQuery::class);
});

test('it returns message collection', function () {
    $folder = new FakeFolder('INBOX');
    $messages = [new FakeMessage(1), new FakeMessage(2)];

    $query = new FakeMessageQuery($folder, $messages);
    $collection = $query->get();

    expect($collection)->toBeInstanceOf(MessageCollection::class);
    expect($collection)->toHaveCount(2);
});

test('it counts messages correctly', function () {
    $folder = new FakeFolder('INBOX');
    $messages = [new FakeMessage(1), new FakeMessage(2), new FakeMessage(3)];

    $query = new FakeMessageQuery($folder, $messages);

    expect($query->count())->toBe(3);
});

test('it returns first message', function () {
    $folder = new FakeFolder('INBOX');
    $messages = [new FakeMessage(1), new FakeMessage(2)];

    $query = new FakeMessageQuery($folder, $messages);

    $first = $query->first();

    expect($first)->toBeInstanceOf(FakeMessage::class);
    expect($first->uid())->toBe(1);
});

test('it returns null when no messages exist for first()', function () {
    $folder = new FakeFolder('INBOX');
    $query = new FakeMessageQuery($folder, []);

    expect($query->first())->toBeNull();
});

test('it throws exception when no messages exist for firstOrFail()', function () {
    $folder = new FakeFolder('INBOX');
    $query = new FakeMessageQuery($folder, []);

    $query->firstOrFail();
})->throws(ItemNotFoundException::class);

test('it auto-increments uid when appending messages', function () {
    $folder = new FakeFolder('INBOX');
    $query = new FakeMessageQuery($folder, []);

    $uid1 = $query->append('First message');
    expect($uid1)->toBe(1);

    $uid2 = $query->append('Second message');
    expect($uid2)->toBe(2);

    $uid3 = $query->append('Third message');
    expect($uid3)->toBe(3);

    expect($query->count())->toBe(3);
});

test('it continues auto-incrementing from last message uid', function () {
    $folder = new FakeFolder('INBOX');
    $messages = [new FakeMessage(5)];

    $query = new FakeMessageQuery($folder, $messages);

    $uid = $query->append('New message');
    expect($uid)->toBe(6);
});

test('it can find message by uid', function () {
    $folder = new FakeFolder('INBOX');
    $messages = [
        new FakeMessage(1),
        new FakeMessage(2),
        new FakeMessage(3),
    ];

    $query = new FakeMessageQuery($folder, $messages);

    $message = $query->find(2);

    expect($message)->toBeInstanceOf(FakeMessage::class);
    expect($message->uid())->toBe(2);
});

test('it returns null when message not found', function () {
    $folder = new FakeFolder('INBOX');
    $messages = [new FakeMessage(1), new FakeMessage(2)];

    $query = new FakeMessageQuery($folder, $messages);

    expect($query->find(999))->toBeNull();
});

test('it throws exception when message not found with findOrFail', function () {
    $folder = new FakeFolder('INBOX');
    $messages = [new FakeMessage(1), new FakeMessage(2)];

    $query = new FakeMessageQuery($folder, $messages);

    $query->findOrFail(999);
})->throws(ItemNotFoundException::class);

test('it can destroy messages by uid', function () {
    $folder = new FakeFolder('INBOX');
    $messages = [
        new FakeMessage(1),
        new FakeMessage(2),
        new FakeMessage(3),
    ];

    $query = new FakeMessageQuery($folder, $messages);

    expect($query->count())->toBe(3);

    $query->destroy(2);

    expect($query->count())->toBe(2);
    expect($query->find(2))->toBeNull();
    expect($query->find(1))->not->toBeNull();
    expect($query->find(3))->not->toBeNull();
});

test('it can destroy multiple messages', function () {
    $folder = new FakeFolder('INBOX');
    $messages = [
        new FakeMessage(1),
        new FakeMessage(2),
        new FakeMessage(3),
        new FakeMessage(4),
    ];

    $query = new FakeMessageQuery($folder, $messages);

    expect($query->count())->toBe(4);

    $query->destroy([1, 3]);

    expect($query->count())->toBe(2);
    expect($query->find(1))->toBeNull();
    expect($query->find(3))->toBeNull();
    expect($query->find(2))->not->toBeNull();
    expect($query->find(4))->not->toBeNull();
});
