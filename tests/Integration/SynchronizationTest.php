<?php

use DirectoryTree\ImapEngine\Fetch\ChangedSince;
use DirectoryTree\ImapEngine\Selection\CondStore;
use DirectoryTree\ImapEngine\Selection\QuickResync;
use DirectoryTree\ImapEngine\Store\UnchangedSince;

beforeEach(function () {
    $capabilities = mailbox()->capabilities();

    foreach (['CONDSTORE', 'QRESYNC', 'UIDPLUS'] as $capability) {
        if (getenv('MAILBOX_SERVER') === 'dovecot') {
            expect($capabilities->supports($capability))->toBeTrue();
        }

        if (! $capabilities->supports($capability)) {
            test()->markTestSkipped("Server does not support {$capability}.");
        }
    }
});

test('changed since returns updated and new messages but not unchanged messages', function () {
    $reader = mailbox();
    $folder = $reader->folders()->create(uniqid('sync-'));

    try {
        $unchanged = $folder->messages()->append("Subject: Unchanged\r\n\r\nBody")->uid();
        $updated = $folder->messages()->append("Subject: Updated\r\n\r\nBody")->uid();

        $checkpoint = $folder->select(true, new CondStore);

        expect($checkpoint->highestModSequence())->toBeGreaterThan(0);

        $writer = mailbox();
        $other = $writer->folders()->findOrFail($folder->path());

        $other->messages()->findOrFail($updated)->markSeen();

        $added = $other->messages()->append("Subject: Added\r\n\r\nBody")->uid();

        // Refresh the selected session's view before querying its saved checkpoint.
        $reader->connection()->noop();

        $changes = $reader->connection()->fetch('1:*', ['UID', 'FLAGS', 'MODSEQ'], modifiers: new ChangedSince($checkpoint->highestModSequence()));

        $messages = $changes->messages()->keyBy(fn ($message) => $message->uid());

        expect($messages->keys()->all())->toEqualCanonicalizing([$updated, $added]);
        expect($messages->has($unchanged))->toBeFalse();
        expect($messages[$updated]->flags())->toContain('\\Seen');
        expect($messages[$updated]->modSequence())->toBeGreaterThan($checkpoint->highestModSequence());
    } finally {
        $folder->delete();
    }
});

test('quick resync reconciles changed and vanished messages after reconnecting', function () {
    $reader = mailbox();
    $folder = $reader->folders()->create(uniqid('resync-'));

    try {
        $updated = $folder->messages()->append("Subject: Updated\r\n\r\nBody")->uid();
        $removed = $folder->messages()->append("Subject: Removed\r\n\r\nBody")->uid();

        $checkpoint = $folder->select(true, new CondStore);

        $reader->disconnect();

        $writer = mailbox();
        $other = $writer->folders()->findOrFail($folder->path());

        $other->messages()->findOrFail($updated)->markSeen();
        $other->messages()->findOrFail($removed)->delete();
        $other->expunge([$removed]);

        $added = $other->messages()->append("Subject: Added\r\n\r\nBody")->uid();

        $reader->reconnect();

        $selection = $folder->select(true, new QuickResync($checkpoint->uidValidity(), $checkpoint->highestModSequence(), [$updated, $removed]));

        expect($selection->uidValidity())->toBe($checkpoint->uidValidity());
        expect($selection->highestModSequence())->toBeGreaterThan($checkpoint->highestModSequence());
        expect($selection->changes()->vanishedUids()->all())->toBe([$removed]);

        $messages = $selection->changes()->messages()->keyBy(fn ($message) => $message->uid());

        expect($messages->has($updated))->toBeTrue();
        expect($messages[$updated]->flags())->toContain('\\Seen');
        expect($folder->messages()->uid($checkpoint->uidNext().':*')->get()->map->uid()->all())->toBe([$added]);
        expect($folder->messages()->get()->map->uid()->all())->toEqualCanonicalizing([$updated, $added]);
    } finally {
        $folder->delete();
    }
});

test('conditional flag updates reject stale checkpoints and accept current checkpoints', function () {
    $reader = mailbox();
    $folder = $reader->folders()->create(uniqid('conflict-'));

    try {
        $uid = $folder->messages()->append("Subject: Conflict\r\n\r\nBody")->uid();

        $checkpoint = $folder->select(true, new CondStore);

        $writer = mailbox();

        $writer->folders()->findOrFail($folder->path())->messages()->findOrFail($uid)->markSeen();

        $result = $reader->connection()->store($uid, ['\\Flagged'], modifiers: new UnchangedSince($checkpoint->highestModSequence()));

        expect($result->modified()->all())->toBe([$uid]);

        $current = $reader->connection()->fetch($uid, ['UID', 'FLAGS', 'MODSEQ'])->messages()[0];

        expect($current->flags())->toContain('\\Seen')->not->toContain('\\Flagged');

        $result = $reader->connection()->store($uid, ['\\Flagged'], modifiers: new UnchangedSince($current->modSequence()));

        expect($result->response()->successful())->toBeTrue();
        expect($result->modified()->all())->toBe([]);

        $current = $reader->connection()->fetch($uid, ['UID', 'FLAGS'])->messages()[0];

        expect($current->flags())->toContain('\\Seen', '\\Flagged');
    } finally {
        $folder->delete();
    }
});
