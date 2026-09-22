<?php

namespace DirectoryTree\ImapEngine\MessageData;

class Body implements FetchItemInterface
{
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
        return "BODY[{$this->section}]";
    }

    /**
     * {@inheritDoc}
     */
    public function toImap(): string
    {
        $item = $this->peek ? 'BODY.PEEK' : 'BODY';

        return "{$item}[{$this->section}]";
    }
}
