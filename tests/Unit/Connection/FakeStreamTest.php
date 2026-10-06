<?php

use DirectoryTree\ImapEngine\Connection\Streams\FakeStream;

test('scripted stream failures preserve queued responses before failing', function (string $failure, string $metadata, bool $readBytes) {
    $stream = (new FakeStream)->{$failure}();
    $stream->open();
    $stream->feed(['first', 'second']);

    if ($readBytes) {
        expect($stream->read(3))->toBe('fir');
        expect($stream->meta()[$metadata])->toBeFalse();
        expect($stream->read(100))->toBe("st\r\nsecond\r\n");
    } else {
        expect($stream->fgets())->toBe("first\r\n");
        expect($stream->meta()[$metadata])->toBeFalse();
        expect($stream->fgets())->toBe("second\r\n");
    }

    expect($stream->meta()[$metadata])->toBeFalse();
    expect($readBytes ? $stream->read(1) : $stream->fgets())->toBeFalse();
    expect($stream->meta()[$metadata])->toBeTrue();
})->with([
    'disconnect while reading lines' => ['disconnectWhenEmpty', 'eof', false],
    'disconnect while reading bytes' => ['disconnectWhenEmpty', 'eof', true],
    'timeout while reading lines' => ['timeoutWhenEmpty', 'timed_out', false],
    'timeout while reading bytes' => ['timeoutWhenEmpty', 'timed_out', true],
]);

test('an unscripted empty stream does not report a disconnect or timeout', function () {
    $stream = new FakeStream;
    $stream->open();

    expect($stream->fgets())->toBeFalse();
    expect($stream->read(1))->toBe('');
    expect($stream->meta())->toMatchArray(['eof' => false, 'timed_out' => false]);

    $stream->feed('arrived later');

    expect($stream->fgets())->toBe("arrived later\r\n");
});
