<?php

use Carbon\Carbon;
use DirectoryTree\ImapEngine\Connection\ImapQueryBuilder;
use DirectoryTree\ImapEngine\DraftMessage;
use DirectoryTree\ImapEngine\Folder;
use DirectoryTree\ImapEngine\Message;
use Illuminate\Support\ItemNotFoundException;

function folder(): Folder
{
    static $folder;

    $folder ??= uniqid('Folder');

    return mailbox()
        ->folders()
        ->firstOrCreate($folder);
}

beforeEach(function () {
    folder()->delete();
});

test('messages selects folder', function () {
    $folder = folder();

    $folder->messages();

    expect($folder->mailbox()->selected($folder))->toBeTrue();
});

test('count', function () {
    $folder = folder();

    $folder->messages()->append(
        new DraftMessage(
            from: 'foo@email.com',
            text: 'hello world',
        ),
    );

    expect($folder->messages()->count())->toBe(1);
});

test('first', function () {
    $folder = folder();

    expect($folder->messages()->first())->toBeNull();

    $uid = $folder->messages()->append(
        new DraftMessage(from: 'foo@example.com', text: 'hello world'),
    );

    expect($folder->messages()->first()->uid())->toBe($uid);
});

test('first or fail', function () {
    $folder = folder();

    expect(fn () => $folder->messages()->firstOrFail())->toThrow(ItemNotFoundException::class);

    $uid = $folder->messages()->append(
        new DraftMessage(from: 'foo@example.com', text: 'hello world'),
    );

    expect($folder->messages()->firstOrFail()->uid())->toBe($uid);
});

test('find', function () {
    $folder = folder();

    $uid = $folder->messages()->append(
        new DraftMessage(
            from: 'foo@email.com',
            text: 'hello world',
        ),
    );

    $message = $folder->messages()->find($uid);

    expect($message)->toBeInstanceOf(Message::class);
});

test('find or fail', function () {
    $folder = folder();

    $uid = $folder->messages()->append(
        new DraftMessage(
            from: 'foo@email.com',
            text: 'hello world',
        ),
    );

    expect($folder->messages()->findOrFail($uid))->toBeInstanceOf(Message::class);

    expect(function () use ($folder) {
        $folder->messages()->findOrFail(999);
    })->toThrow(ItemNotFoundException::class);
});

test('append', function () {
    $folder = folder();

    $messages = $folder->messages();

    $uid = $messages->append(
        new DraftMessage(
            from: 'foo@email.com',
            to: 'bar@email.com',
            cc: 'baz@email.com',
            text: 'hello world',
            html: '<p>hello world</p>',
            attachments: [
                stub('attachment.txt'),
            ],
            date: $datetime = Carbon::now()->subYear(),
        ),
        ['\\Seen'],
    );

    $message = $messages
        ->withHeaders()
        ->withFlags()
        ->withBody()
        ->find($uid);

    expect($message->from()->email())->toBe('foo@email.com');
    expect($message->to()[0]->email())->toBe('bar@email.com');
    expect($message->cc()[0]->email())->toBe('baz@email.com');
    expect($message->date()->is($datetime))->toBeTrue();
    expect($message->text())->toBe('hello world');
    expect($message->html())->toBe('<p>hello world</p>');
    expect($message->hasAttachments())->toBeTrue();
    expect($message->attachmentCount())->toBe(1);
    expect($message->attachments()[0]->filename())->toBe('attachment.txt');
    expect($message->flags())->toBe(['\\Seen']);
});

test('flag', function () {
    $folder = folder();

    $messages = $folder->messages();

    $uid = $messages->append(
        new DraftMessage(
            from: 'foo@email.com',
            text: 'flag test'
        )
    );

    // Initially, message should not be marked as seen.
    $message = $messages->withFlags()->find($uid);
    expect($message->isSeen())->toBeFalse();

    // Mark message as seen.
    $message->markSeen();
    $message = $messages->withFlags()->find($uid);
    expect($message->isSeen())->toBeTrue();

    // Unmark message as seen.
    $message->unmarkSeen();
    $message = $messages->withFlags()->find($uid);
    expect($message->isSeen())->toBeFalse();
});

test('copy', function () {
    $folder = folder();

    $messages = $folder->messages();

    $uid = $messages->append(
        new DraftMessage(
            from: 'foo@email.com',
            text: 'copy test'
        )
    );

    $message = $messages->withHeaders()->withBody()->find($uid);

    $targetFolder = $folder->mailbox()->folders()->firstOrCreate(
        $targetFolderName = uniqid()
    );

    $message->copy($targetFolderName);

    $targetMessages = $targetFolder->messages()
        ->withHeaders()
        ->withBody()
        ->get();

    expect($targetMessages->count())->toBe(1);

    /** @var Message $movedMessage */
    $movedMessage = $targetMessages->first();

    expect($movedMessage->from()->email())->toBe('foo@email.com');
    expect($movedMessage->text())->toBe('copy test');
});

test('move', function () {
    $folder = folder();

    $messages = $folder->messages();

    $uid = $messages->append(
        new DraftMessage(
            from: 'foo@email.com',
            text: 'move test'
        )
    );

    $message = $messages->withHeaders()->withBody()->find($uid);

    $targetFolder = $folder->mailbox()->folders()->firstOrCreate(
        $targetFolderName = uniqid()
    );

    $message->move($targetFolderName);

    $targetMessages = $targetFolder->messages()
        ->withHeaders()
        ->withBody()
        ->get();

    expect($targetMessages->count())->toBe(1);

    /** @var Message $movedMessage */
    $movedMessage = $targetMessages->first();

    expect($movedMessage->from()->email())->toBe('foo@email.com');
    expect($movedMessage->text())->toBe('move test');
});

test('delete', function () {
    $messages = folder()->messages();

    $uid = $messages->append(
        new DraftMessage(
            from: 'foo@email.com',
            text: 'delete test'
        )
    );

    $message = $messages->find($uid);

    $message->delete();

    expect($messages->withFlags()->find($uid)->isDeleted())->toBeTrue();
});

test('retrieves messages using or statement', function () {
    $folder = folder();

    $firstUid = $folder->messages()->append(
        new DraftMessage(
            from: 'foo@email.com',
            text: $firstUuid = uniqid(),
        ),
    );

    $secondUid = $folder->messages()->append(
        new DraftMessage(
            from: 'foo@email.com',
            text: $secondUuid = uniqid(),
        ),
    );

    $results = $folder->messages()
        ->where(fn (ImapQueryBuilder $q) => $q->body($firstUuid))
        ->orWhere(fn (ImapQueryBuilder $q) => $q->body($secondUuid))
        ->get();

    expect($results->count())->toBe(2);
    expect($results->find($firstUid))->toBeInstanceOf(Message::class);
    expect($results->find($secondUid))->toBeInstanceOf(Message::class);
});

test('retrieves messages by flag', function (string $flag, string $criteria) {
    $folder = folder();

    $uid = $folder->messages()->append(
        new DraftMessage(
            from: 'foo@email.com',
            text: 'hello world',
        ),
        [$flag],
    );

    expect(
        $folder->messages()
            ->where($criteria)
            ->body('hello world')
            ->first()
            ->uid()
    )->toBe($uid);

    expect(
        $folder->messages()
            ->where($criteria)
            ->body('invalid')
            ->first()
    )->toBeNull();
})->with([
    ['\\Seen', 'SEEN'],
    ['\\Draft', 'DRAFT'],
    ['\\Deleted', 'DELETED'],
    ['\\Flagged', 'FLAGGED'],
    ['\\Answered', 'ANSWERED'],
]);
