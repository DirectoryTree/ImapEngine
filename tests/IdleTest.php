<?php

use DirectoryTree\ImapEngine\Connection\FakeStream;
use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Idle;
use DirectoryTree\ImapEngine\Mailbox;

test('idle', function () {
    $stream = new FakeStream;

    $stream->open();

    $stream->feed([
        // Initial connection
        '* OK IMAP4rev1 Service Ready',

        // Login response
        'TAG1 OK Logged in',

        // Select folder response
        '* FLAGS (\Answered \Flagged \Deleted \Seen \Draft $Forwarded)',
        '* OK [PERMANENTFLAGS (\Answered \Flagged \Deleted \Seen \Draft $Forwarded \*)] Flags permitted.',
        '* 3 EXISTS',
        '* 0 RECENT',
        '* OK [UIDVALIDITY 1707169026] UIDs valid',
        '* OK [UIDNEXT 626] Predicted next UID',
        '* OK [HIGHESTMODSEQ 4578] Highest',
        'TAG2 OK [READ-WRITE] Select completed (0.002 + 0.000 + 0.001 secs).',

        // Idling response
        '+ idling',

        // New message arrival
        '* 24 EXISTS',
        '* 24 FETCH (FLAGS (\Seen) UID 12345)',

        // Logout
        'TAG4 OK LOGOUT completed',
    ]);

    $mailbox = new Mailbox;

    $mailbox->connect(new ImapConnection($stream));

    try {
        (new Idle($mailbox, 'INBOX', 10))->await(
            function ($msgn, $sequence) use (&$receivedMsgn, &$receivedSequence) {
                $this->assertEquals(24, $msgn);
                $this->assertEquals(1, $sequence);
            }
        );
    } catch (Exception) {
        // Do nothing.
    }
});
