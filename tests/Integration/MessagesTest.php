<?php

use Carbon\Carbon;
use DirectoryTree\ImapEngine\Connection\ImapQueryBuilder;
use DirectoryTree\ImapEngine\DraftMessage;
use DirectoryTree\ImapEngine\Enums\ImapFetchItem;
use DirectoryTree\ImapEngine\Folder;
use DirectoryTree\ImapEngine\Message;
use DirectoryTree\ImapEngine\MessageQuery;
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
    )->uid();

    expect($folder->messages()->first()->uid())->toBe($uid);
});

test('first or fail', function () {
    $folder = folder();

    $uid = $folder->messages()->append(
        new DraftMessage(from: 'foo@example.com', text: 'hello world'),
    )->uid();

    $message = $folder->messages()->firstOrFail();

    expect($message->uid())->toBe($uid);
});

test('first or fail throws exception', function () {
    $folder = folder();

    $folder->messages()->firstOrFail();
})->throws(ItemNotFoundException::class);

test('find', function () {
    $folder = folder();

    $uid = $folder->messages()->append(
        new DraftMessage(
            from: 'foo@email.com',
            text: 'hello world',
        ),
    )->uid();

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
    )->uid();

    $message = $folder->messages()->findOrFail($uid);

    expect($message)->toBeInstanceOf(Message::class);
});

test('find or fail throws exception', function () {
    $folder = folder();

    $folder->messages()->findOrFail(999);
})->throws(ItemNotFoundException::class);

test('get without fetches', function () {
    $folder = folder();

    $uid = $folder->messages()->append(
        new DraftMessage(
            from: 'foo@email.com',
            text: 'hello world',
        ),
    )->uid();

    $messages = $folder->messages()->get();

    expect($messages->count())->toBe(1);
    expect($messages->first()->uid())->toBe($uid);
});

test('get with fetches', function (callable $callback) {
    $folder = folder();

    $uid = $folder->messages()->append(
        new DraftMessage(
            from: 'foo@email.com',
            text: 'hello world',
        ),
    )->uid();

    $messages = $callback($folder->messages())->get();

    expect($messages->count())->toBe(1);
    expect($messages->first()->uid())->toBe($uid);
})->with([
    fn (MessageQuery $query) => $query->with(ImapFetchItem::Body),
    fn (MessageQuery $query) => $query->with(ImapFetchItem::Flags),
    fn (MessageQuery $query) => $query->with(ImapFetchItem::Headers),
    fn (MessageQuery $query) => $query->with(ImapFetchItem::Size),
]);

test('get with size', function () {
    $folder = folder();

    $uid = $folder->messages()->append(
        new DraftMessage(
            from: 'foo@email.com',
            to: 'bar@email.com',
            subject: 'Test Subject',
            text: 'hello world',
        ),
    )->uid();

    // Fetch without size - should be null
    $messagesWithoutSize = $folder->messages()->get();
    expect($messagesWithoutSize->first()->size())->toBeNull();

    // Fetch with size - should have a value
    $messagesWithSize = $folder->messages()->with(ImapFetchItem::Size)->get();
    $message = $messagesWithSize->first();

    expect($message->size())->toBeInt();
    expect($message->size())->toBeGreaterThan(0);
    expect($message->uid())->toBe($uid);
});

test('size reflects actual message size', function () {
    $folder = folder();

    $shortMessage = new DraftMessage(
        from: 'foo@email.com',
        text: 'short',
    );

    $longMessage = new DraftMessage(
        from: 'foo@email.com',
        text: str_repeat('This is a longer message with more content. ', 100),
    );

    $uid1 = $folder->messages()->append($shortMessage)->uid();
    $uid2 = $folder->messages()->append($longMessage)->uid();

    $messages = $folder->messages()->with(ImapFetchItem::Size)->get();

    $short = $messages->find($uid1);
    $long = $messages->find($uid2);

    expect($short->size())->toBeInt();
    expect($long->size())->toBeInt();
    expect($long->size())->toBeGreaterThan($short->size());
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
    )->uid();

    $message = $messages
        ->with(
            ImapFetchItem::Headers,
            ImapFetchItem::Flags,
            ImapFetchItem::Body,
        )
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
    )->uid();

    // Initially, message should not be marked as seen.
    $message = $messages->with(ImapFetchItem::Flags)->find($uid);
    expect($message->isSeen())->toBeFalse();

    // Mark message as seen.
    $message->markSeen();
    $message = $messages->with(ImapFetchItem::Flags)->find($uid);
    expect($message->isSeen())->toBeTrue();

    // Unmark message as seen.
    $message->unmarkSeen();
    $message = $messages->with(ImapFetchItem::Flags)->find($uid);
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
    )->uid();

    $message = $messages->with(ImapFetchItem::Headers, ImapFetchItem::Body)->find($uid);

    $targetFolder = $folder->mailbox()->folders()->firstOrCreate(
        $targetFolderName = uniqid()
    );

    $newUid = $message->copy($targetFolderName);

    expect($newUid)->toBeInt();
    expect($newUid)->toBeGreaterThan(0);

    $copiedMessage = $targetFolder->messages()
        ->with(ImapFetchItem::Body, ImapFetchItem::Headers)
        ->findOrFail($newUid);

    expect($copiedMessage->from()->email())->toBe('foo@email.com');
    expect($copiedMessage->text())->toBe('copy test');
});

test('move', function () {
    $folder = folder();

    $messages = $folder->messages();

    $uid = $messages->append(
        new DraftMessage(
            from: 'foo@email.com',
            text: 'move test'
        )
    )->uid();

    $message = $messages->with(ImapFetchItem::Headers, ImapFetchItem::Body)->find($uid);

    $targetFolder = $folder->mailbox()->folders()->firstOrCreate(
        $targetFolderName = uniqid()
    );

    expect($message->move($targetFolderName))->toBeNull();

    $targetMessages = $targetFolder->messages()
        ->with(ImapFetchItem::Headers, ImapFetchItem::Body)
        ->get();

    expect($folder->messages()->count())->toBe(0);
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
    )->uid();

    $message = $messages->find($uid);

    $message->delete();

    expect($messages->with(ImapFetchItem::Flags)->find($uid)->isDeleted())->toBeTrue();
});

test('retrieves messages using or statement', function () {
    $folder = folder();

    $firstUid = $folder->messages()->append(
        new DraftMessage(
            from: 'foo@email.com',
            text: $firstUuid = uniqid(),
        ),
    )->uid();

    $secondUid = $folder->messages()->append(
        new DraftMessage(
            from: 'foo@email.com',
            text: $secondUuid = uniqid(),
        ),
    )->uid();

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
    )->uid();

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

test('marks messages as read when fetching', function () {
    $folder = folder();

    $uid = $folder->messages()->append(
        new DraftMessage(
            from: 'foo@email.com',
            text: 'hello world',
        ),
    )->uid();

    $folder->messages()
        ->markAsRead()
        ->with(ImapFetchItem::Headers)
        ->get();

    $message = $folder->messages()->with(ImapFetchItem::Flags)->find($uid);

    expect($message->isSeen())->toBeTrue();
});

test('leaves messages unread when fetching', function () {
    $folder = folder();

    $uid = $folder->messages()->append(
        new DraftMessage(
            from: 'foo@email.com',
            text: 'hello world',
        ),
    )->uid();

    $folder->messages()
        ->leaveUnread()
        ->with(ImapFetchItem::Headers)
        ->get();

    $message = $folder->messages()->with(ImapFetchItem::Flags)->find($uid);

    expect($message->isSeen())->toBeFalse();
});

test('querying for unseen messages', function () {
    $folder = folder();

    $uid = $folder->messages()->append(
        new DraftMessage(
            from: 'foo@email.com',
            text: 'hello world',
        ),
    )->uid();

    expect($folder->messages()->unseen()->count())->toBe(1);

    $folder->messages()->with(ImapFetchItem::Flags)->find($uid)->markSeen();

    expect($folder->messages()->unseen()->count())->toBe(0);
});

test('sort by subject', function () {
    $folder = folder();

    $uid1 = $folder->messages()->append(
        new DraftMessage(
            from: 'foo@email.com',
            subject: 'AAA First alphabetically',
            text: 'hello world',
        ),
    )->uid();

    $uid2 = $folder->messages()->append(
        new DraftMessage(
            from: 'foo@email.com',
            subject: 'ZZZ Last alphabetically',
            text: 'hello world',
        ),
    )->uid();

    // Ascending order: AAA should come before ZZZ
    $messagesAsc = $folder->messages()->sortBy('subject', 'asc')->get();
    expect($messagesAsc->map(fn (Message $message) => $message->uid())->all())->toEqual([$uid1, $uid2]);

    // Descending order: ZZZ should come before AAA
    $messagesDesc = $folder->messages()->sortBy('subject', 'desc')->get();
    expect($messagesDesc->map(fn (Message $message) => $message->uid())->all())->toEqual([$uid2, $uid1]);
});
