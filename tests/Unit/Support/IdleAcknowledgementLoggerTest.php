<?php

use Tests\Support\IdleAcknowledgementLogger;

test('the idle hook ignores unrelated continuations and unsolicited responses', function () {
    $calls = 0;

    $logger = new IdleAcknowledgementLogger(function () use (&$calls) {
        $calls++;
    });

    $logger->sent('TAG1 APPEND "INBOX" {10}');
    $logger->received('+ Ready for literal');

    $logger->sent('TAG2 IDLE');
    $logger->received('* 1 EXISTS');

    expect($calls)->toBe(0);
    expect($logger->acknowledged)->toBeFalse();

    $logger->received('+ Idling');

    expect($calls)->toBe(1);
    expect($logger->acknowledged)->toBeTrue();
});

test('the idle hook invokes its callback only once across renewals', function () {
    $calls = 0;

    $logger = new IdleAcknowledgementLogger(function () use (&$calls) {
        $calls++;
    });

    $logger->sent('TAG1 IDLE');
    $logger->received('+ Idling');

    $logger->sent('DONE');
    $logger->received('TAG1 OK IDLE completed');

    $logger->sent('TAG2 IDLE');
    $logger->received('+ Idling');

    expect($calls)->toBe(1);
});

test('a rejected idle command does not arm the next unrelated continuation', function () {
    $calls = 0;

    $logger = new IdleAcknowledgementLogger(function () use (&$calls) {
        $calls++;
    });

    $logger->sent('TAG1 IDLE');
    $logger->received('TAG1 NO IDLE unavailable');

    $logger->sent('TAG2 APPEND "INBOX" {10}');
    $logger->received('+ Ready for literal');

    expect($calls)->toBe(0);
    expect($logger->acknowledged)->toBeFalse();

    $logger->sent('TAG3 IDLE');
    $logger->received('+ Idling');

    expect($calls)->toBe(1);
});
