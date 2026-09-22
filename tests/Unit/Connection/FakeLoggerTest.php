<?php

use DirectoryTree\ImapEngine\Connection\Loggers\FakeLogger;

test('it records sent and received messages', function () {
    $logger = new FakeLogger;

    $logger->sent('sent message');
    $logger->sent('sent message');
    $logger->received('received message');

    $logger->assertSent('sent message', times: 2);
    $logger->assertReceived('received message');
    $logger->assertNotSent('other sent message');
    $logger->assertNotReceived('other received message');
});
