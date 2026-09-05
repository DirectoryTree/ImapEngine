<?php

namespace DirectoryTree\ImapEngine;

use DirectoryTree\ImapEngine\Connection\Responses\Data\Data;
use DirectoryTree\ImapEngine\Connection\Responses\Data\ListData;
use DirectoryTree\ImapEngine\Connection\Responses\Data\ResponseCodeData;
use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
use DirectoryTree\ImapEngine\Connection\Tokens\EmailAddress;
use DirectoryTree\ImapEngine\Connection\Tokens\Nil;
use DirectoryTree\ImapEngine\Connection\Tokens\Token;
use DirectoryTree\ImapEngine\Exceptions\RuntimeException;
use Illuminate\Contracts\Support\Arrayable;

class FetchedMessageData implements Arrayable
{
    /**
     * Constructor.
     */
    public function __construct(
        protected array $attributes = []
    ) {
        $this->attributes = array_change_key_case($attributes, CASE_UPPER);
    }

    /**
     * Create message data from an IMAP FETCH response.
     */
    public static function fromResponse(UntaggedResponse $response): static
    {
        $data = $response->tokenAt(3);

        if (! $data instanceof ListData) {
            throw new RuntimeException(sprintf(
                'Expected instance of %s at index 3 in FETCH response, got %s',
                ListData::class,
                get_debug_type($data)
            ));
        }

        $tokens = $data->tokens();

        $attributes = [];

        for ($index = 0; $index < count($tokens);) {
            $key = strtoupper($tokens[$index++]->value);

            // Section specifiers and partial offsets belong to the attribute
            // name, not its value. Keep them so multiple sections can coexist.
            if (
                in_array($key, ['BODY', 'BINARY', 'BINARY.SIZE'])
                && ($tokens[$index] ?? null) instanceof ResponseCodeData
            ) {
                $key .= strtoupper((string) $tokens[$index++]);

                if (($tokens[$index] ?? null) instanceof EmailAddress) {
                    $key .= (string) $tokens[$index++];
                }
            }

            $attributes[$key] = $tokens[$index++];
        }

        return new static($attributes);
    }

    /**
     * Determine if an attribute was returned, including an explicit NIL value.
     */
    public function has(string $key): bool
    {
        return array_key_exists(strtoupper($key), $this->attributes);
    }

    /**
     * Get an attribute as a PHP value. IMAP numbers retain their string value.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->has($key)
            ? $this->value($this->attributes[strtoupper($key)])
            : $default;
    }

    /**
     * Create a copy containing the given attributes, leaving omitted values intact.
     */
    public function merge(array|self $attributes): static
    {
        return new static(array_replace(
            $this->attributes,
            $attributes instanceof self
                ? $attributes->attributes
                : array_change_key_case($attributes, CASE_UPPER),
        ));
    }

    /**
     * Get the message UID.
     */
    public function uid(): int
    {
        return (int) $this->get('UID');
    }

    /**
     * Get the message flags.
     */
    public function flags(): array
    {
        return $this->get('FLAGS') ?? [];
    }

    /**
     * Get the message headers.
     */
    public function head(): string
    {
        return $this->get('BODY[HEADER]') ?? '';
    }

    /**
     * Get the message text body.
     */
    public function body(): string
    {
        return $this->get('BODY[TEXT]') ?? '';
    }

    /**
     * Get the message size in bytes.
     */
    public function size(): ?int
    {
        $size = $this->get('RFC822.SIZE');

        return is_null($size) ? null : (int) $size;
    }

    /**
     * Get the message body structure tokens.
     */
    public function bodyStructure(): ?ListData
    {
        $structure = $this->attributes['BODYSTRUCTURE'] ?? null;

        return $structure instanceof ListData ? $structure : null;
    }

    /**
     * Get the message modification sequence.
     */
    public function modSequence(): ?int
    {
        $sequence = $this->get('MODSEQ')[0] ?? null;

        return is_null($sequence) ? null : (int) $sequence;
    }

    /**
     * Get all returned attributes as PHP values.
     */
    public function toArray(): array
    {
        return array_map($this->value(...), $this->attributes);
    }

    /**
     * Convert protocol tokens to PHP values without losing nested lists or NIL.
     */
    protected function value(mixed $value): mixed
    {
        return match (true) {
            $value instanceof Nil => null,
            $value instanceof Data => array_map($this->value(...), $value->tokens()),
            $value instanceof Token => $value->value,
            is_array($value) => array_map($this->value(...), $value),
            default => $value,
        };
    }

    /**
     * Create a message for the given folder.
     */
    public function toMessage(FolderInterface $folder): Message
    {
        return new Message($folder, $this);
    }
}
