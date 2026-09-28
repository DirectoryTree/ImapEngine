<?php

namespace DirectoryTree\ImapEngine\Idle;

use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
use DirectoryTree\ImapEngine\Connection\Tokens\Number;
use DirectoryTree\ImapEngine\Idle\Events\MessageExpunged;
use DirectoryTree\ImapEngine\Idle\Events\MessageFetched;
use DirectoryTree\ImapEngine\Idle\Events\MessagesExist;
use DirectoryTree\ImapEngine\Idle\Events\MessagesVanished;
use DirectoryTree\ImapEngine\Idle\Events\ResponseEvent;
use DirectoryTree\ImapEngine\Idle\Events\UnknownEvent;

class EventFactory
{
    /**
     * Convert an unsolicited response into a mailbox event.
     */
    public static function fromResponse(string $folder, UntaggedResponse $response): ResponseEvent
    {
        $event = $response->type() instanceof Number
            ? match (strtoupper($response->tokenAt(2)->value)) {
                'EXISTS' => MessagesExist::class,
                'FETCH' => MessageFetched::class,
                'EXPUNGE' => MessageExpunged::class,
                default => UnknownEvent::class,
            }
        : match (strtoupper($response->type()->value)) {
            'VANISHED' => MessagesVanished::class,
            default => UnknownEvent::class,
        };

        return new $event($folder, $response);
    }
}
