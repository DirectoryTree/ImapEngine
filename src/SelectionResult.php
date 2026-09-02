<?php

namespace DirectoryTree\ImapEngine;

use Countable;
use DirectoryTree\ImapEngine\Collections\ResponseCollection;
use DirectoryTree\ImapEngine\Connection\Responses\Data\ListData;
use DirectoryTree\ImapEngine\Connection\Responses\Data\ResponseCodeData;
use DirectoryTree\ImapEngine\Connection\Tokens\Token;

class SelectionResult implements Countable
{
    /**
     * Constructor.
     */
    public function __construct(
        protected ?int $exists = null,
        protected ?int $recent = null,
        protected ?int $uidValidity = null,
        protected ?int $uidNext = null,
        protected ?int $highestModSequence = null,
        protected array $permanentFlags = [],
        protected bool $supportsModSequences = true,
        protected ?MessageChanges $changes = null,
        protected ?ResponseCollection $responses = null,
    ) {}

    /**
     * Create a selection result from IMAP responses.
     */
    public static function fromResponses(ResponseCollection $responses): static
    {
        $exists = null;
        $recent = null;
        $uidValidity = null;
        $uidNext = null;
        $highestModSequence = null;
        $permanentFlags = [];
        $supportsModSequences = true;

        foreach ($responses->untagged() as $response) {
            $type = $response->tokenAt(2);

            if ($type instanceof Token && $type->is('EXISTS')) {
                $exists = (int) $response->type()->value;
            } elseif ($type instanceof Token && $type->is('RECENT')) {
                $recent = (int) $response->type()->value;
            }

            $code = $type;

            if (! $code instanceof ResponseCodeData) {
                continue;
            }

            $name = strtoupper($code->first()?->value ?? '');
            $value = $code->tokenAt(1);

            match ($name) {
                'UIDVALIDITY' => $uidValidity = (int) $value->value,
                'UIDNEXT' => $uidNext = (int) $value->value,
                'HIGHESTMODSEQ' => $highestModSequence = (int) $value->value,
                'NOMODSEQ' => $supportsModSequences = false,
                'PERMANENTFLAGS' => $permanentFlags = $value instanceof ListData ? $value->values() : [],
                default => null,
            };
        }

        return new static(
            $exists,
            $recent,
            $uidValidity,
            $uidNext,
            $highestModSequence,
            $permanentFlags,
            $supportsModSequences,
            MessageChanges::fromResponses($responses),
            $responses,
        );
    }

    /**
     * Get the number of messages in the folder.
     */
    public function exists(): ?int
    {
        return $this->exists;
    }

    /**
     * Get the number of messages with the recent flag.
     */
    public function recent(): ?int
    {
        return $this->recent;
    }

    /**
     * Get the folder UID validity value.
     */
    public function uidValidity(): ?int
    {
        return $this->uidValidity;
    }

    /**
     * Get the predicted next message UID.
     */
    public function uidNext(): ?int
    {
        return $this->uidNext;
    }

    /**
     * Get the highest modification sequence in the folder.
     */
    public function highestModSequence(): ?int
    {
        return $this->highestModSequence;
    }

    /**
     * Get the flags that can be permanently changed.
     */
    public function permanentFlags(): array
    {
        return $this->permanentFlags;
    }

    /**
     * Determine if the folder supports persistent modification sequences.
     */
    public function supportsModSequences(): bool
    {
        return $this->supportsModSequences;
    }

    /**
     * Get changes returned while selecting the folder.
     */
    public function changes(): MessageChanges
    {
        return $this->changes ?? new MessageChanges;
    }

    /**
     * Get the raw IMAP responses.
     */
    public function responses(): ResponseCollection
    {
        return $this->responses ?? new ResponseCollection;
    }

    /**
     * Count the untagged selection responses.
     */
    public function count(): int
    {
        return $this->responses()->untagged()->count();
    }
}
