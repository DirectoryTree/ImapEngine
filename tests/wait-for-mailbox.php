<?php

use DirectoryTree\ImapEngine\Mailbox;

require __DIR__.'/../vendor/autoload.php';

$mailbox = new Mailbox([
    'host' => getenv('MAILBOX_HOST') ?: '127.0.0.1',
    'port' => (int) (getenv('MAILBOX_PORT') ?: 3143),
    'username' => getenv('MAILBOX_USERNAME') ?: 'user@localhost.com',
    'password' => getenv('MAILBOX_PASSWORD') ?: 'password',
    'encryption' => getenv('MAILBOX_ENCRYPTION') ?: null,
    'timeout' => 1,
]);

for ($attempt = 1; $attempt <= 30; $attempt++) {
    try {
        $mailbox->connect();
        $mailbox->disconnect();

        exit(0);
    } catch (Exception $exception) {
        $mailbox->disconnect();

        if ($attempt === 30) {
            throw $exception;
        }

        sleep(1);
    }
}
