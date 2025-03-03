<?php

use Carbon\Carbon;
use DirectoryTree\ImapEngine\Address;
use DirectoryTree\ImapEngine\Mbox;

test('it can read messages', function () {
    $mbox = new Mbox(stub('mailbox.mbox'));

    $messages = iterator_to_array($mbox->messages());

    expect($messages)->toHaveCount(40);

    foreach ($messages as $message) {
        expect($message->from())->toBeInstanceOf(Address::class);
        expect($message->date())->toBeInstanceOf(Carbon::class);
    }
});
