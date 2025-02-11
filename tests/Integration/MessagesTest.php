<?php

use Carbon\Carbon;
use DirectoryTree\ImapEngine\DraftMessage;

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

    $message = $inbox->messages()
        ->withHeaders()
        ->withFlags()
        ->withBody()
        ->find($uid);

    expect($message->from()->email())->toBe('foo@email.com');
    expect($message->to()[0]->email())->toBe('bar@email.com');
    expect($message->cc()[0]->email())->toBe('baz@email.com');
    expect($message->dateTime()->is($datetime))->toBeTrue();
    expect($message->text())->toBe('hello world');
    expect($message->html())->toBe('<p>hello world</p>');
    expect($message->flags())->toBe(['\\Seen']);
});
