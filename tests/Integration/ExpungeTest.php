<?php

test('targeted expunge preserves another clients deleted message', function () {
    $reader = mailbox();
    $folder = $reader->folders()->create(uniqid('expunge-'));

    try {
        $target = $folder->messages()->append("Subject: Target\r\n\r\nBody")->uid();
        $retained = $folder->messages()->append("Subject: Retained\r\n\r\nBody")->uid();

        $writer = mailbox();

        $writer->folders()->findOrFail($folder->path())->messages()->findOrFail($retained)->delete();
        $folder->messages()->findOrFail($target)->delete();

        $folder->expunge([$target]);

        expect($folder->messages()->find($target))->toBeNull();

        $messages = $reader->connection()->fetch('1:*', ['UID', 'FLAGS'])->messages();

        expect($messages)->toHaveCount(1);
        expect($messages[0]->uid())->toBe($retained);
        expect($messages[0]->flags())->toContain('\\Deleted');
    } finally {
        $folder->delete();
    }
});
