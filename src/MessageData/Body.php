<?php

namespace DirectoryTree\ImapEngine\MessageData;

use InvalidArgumentException;

class Body implements FetchItemInterface
{
    /**
     * The zero-based offset in the transfer-encoded section, or null for a full fetch.
     */
    protected ?int $offset = null;

    /**
     * The maximum number of transfer-encoded bytes to fetch.
     */
    protected ?int $length = null;

    /**
     * Fetch a byte range of the transfer-encoded section.
     */
    public function partial(int $offset, int $length): static
    {
        if ($offset < 0 || $length < 1) {
            throw new InvalidArgumentException('Partial fetches require a non-negative offset and a positive length.');
        }

        $item = clone $this;
        $item->offset = $offset;
        $item->length = $length;

        return $item;
    }

    /**
     * Constructor.
     */
    public function __construct(
        protected string $section,
        protected bool $peek = false,
    ) {}

    /**
     * Create a message header data item.
     */
    public static function headers(): static
    {
        return new static('HEADER');
    }

    /**
     * Create a message text data item.
     */
    public static function text(): static
    {
        return new static('TEXT');
    }

    /**
     * Create a message body section data item.
     */
    public static function section(string $section): static
    {
        return new static($section);
    }

    /**
     * Fetch the message body section without setting the seen flag.
     */
    public function peek(): static
    {
        $item = clone $this;
        $item->peek = true;

        return $item;
    }

    /**
     * {@inheritDoc}
     */
    public function key(): string
    {
        return "BODY[{$this->section}]".(is_null($this->offset) ? '' : "<{$this->offset}>");
    }

    /**
     * {@inheritDoc}
     */
    public function toImap(): string
    {
        $item = $this->peek ? 'BODY.PEEK' : 'BODY';

        return "{$item}[{$this->section}]".(is_null($this->offset) ? '' : "<{$this->offset}.{$this->length}>");
    }
}
