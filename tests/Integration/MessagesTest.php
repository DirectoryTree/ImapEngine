<?php

use Carbon\Carbon;
use DirectoryTree\ImapEngine\DraftMessage;
use DirectoryTree\ImapEngine\Folder;
use DirectoryTree\ImapEngine\Message;
use Illuminate\Support\Str;

function folder(): Folder
{
    return mailbox()->folders()->firstOrCreate('test-folder');
}

beforeEach(function () {
    folder()->delete();
});

afterEach(function () {
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
    expect($message->flags())->toBe(['\\Seen']);
});

test('retrieves messages using or statement', function () {
    $folder = folder();

    $firstUid = $folder->messages()->append(
        new DraftMessage(
            from: 'foo@email.com',
            text: $firstUuid = (string) Str::uuid(),
        ),
    );

    $secondUid = $folder->messages()->append(
        new DraftMessage(
            from: 'foo@email.com',
            text: $secondUuid = (string) Str::uuid(),
        ),
    );

    $results = $folder->messages()
        ->orWhere()
        ->body($firstUuid)
        ->body($secondUuid)
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
