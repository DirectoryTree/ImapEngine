<?php

namespace Tests;

use DirectoryTree\ImapEngine\Mailbox;
use PHPUnit\Framework\TestCase;

class IntegrationTestCase extends TestCase
{
    /**
     * The mailboxes opened by the current test.
     *
     * @var array<Mailbox>
     */
    public array $mailboxes = [];

    protected function tearDown(): void
    {
        foreach ($this->mailboxes as $mailbox) {
            $mailbox->disconnect();
        }

        $this->mailboxes = [];

        parent::tearDown();
    }
}
