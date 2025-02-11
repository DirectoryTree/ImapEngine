<?php

use Carbon\Carbon;
use DirectoryTree\ImapEngine\DraftMessage;
use DirectoryTree\ImapEngine\Message;
use Illuminate\Support\Str;

test('messages selects folder', function () {
    $mailbox = mailbox();

    $inbox = $mailbox->inbox();

    $inbox->messages();

    expect($mailbox->selected($inbox))->toBeTrue();
});

test('append', function () {
    $inbox = mailbox()->inbox();

    $messages = $inbox->messages();

    $uid = $messages->append(
        new DraftMessage(
            from: 'foo@email.com',
            to: 'bar@email.com',
            cc: 'baz@email.com',
            text: 'hello world',
            html: '<p>hello world</p>',
            datetime: $datetime = Carbon::now()->subYear(),
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
    $inbox = mailbox()->inbox();

    $firstUid = $inbox->messages()->append(
        new DraftMessage(
            from: 'foo@email.com',
            text: $firstUuid = (string) Str::uuid(),
        ),
    );

    $secondUid = $inbox->messages()->append(
        new DraftMessage(
            from: 'foo@email.com',
            text: $secondUuid = (string) Str::uuid(),
        ),
    );

    $results = $inbox->messages()
        ->orWhere()
        ->body($firstUuid)
        ->body($secondUuid)
        ->get();

    expect($results->count())->toBe(2);
    expect($results->find($firstUid))->toBeInstanceOf(Message::class);
    expect($results->find($secondUid))->toBeInstanceOf(Message::class);
});

test('retrieves messages by flag', function (string $flag, string $criteria) {
    $inbox = mailbox()->inbox();

    $uid = $inbox->messages()->append(
        new DraftMessage(
            from: 'foo@email.com',
            text: 'hello world',
        ),
        [$flag],
    );

    expect(
        $inbox->messages()
            ->where($criteria)
            ->body('hello world')
            ->first()
            ->uid()
    )->toBe($uid);

    expect(
        $inbox->messages()
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
