<?php

namespace DirectoryTree\ImapEngine;

readonly class Capabilities
{
    /**
     * The mailbox capabilities.
     *
     * @var array<string, Capability>
     */
    protected array $items;

    /**
     * Constructor.
     *
     * @param  array<string, Capability>  $items
     */
    protected function __construct(array $items)
    {
        $this->items = $items;
    }

    /**
     * Create a capability collection from the given items.
     */
    public static function from(Capability ...$capabilities): static
    {
        $items = [];

        foreach ($capabilities as $capability) {
            $items[$capability->name()] = $capability;
        }

        return new static($items);
    }

    /**
     * Get the capability items.
     *
     * @return array<string, Capability>
     */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * Get all supported capabilities.
     */
    public function all(): array
    {
        return array_keys($this->items);
    }

    /**
     * Determine if the exact capability exists.
     */
    public function has(string $capability): bool
    {
        return isset($this->items[strtoupper($capability)]);
    }

    /**
     * Determine if the capability is supported.
     */
    public function supports(string $capability): bool
    {
        return (bool) $this->find($capability);
    }

    /**
     * Determine if the capability is enabled.
     */
    public function enabled(string $capability): bool
    {
        return ($this->items[strtoupper($capability)] ?? null)?->enabled() ?? false;
    }

    /**
     * Find a supported capability.
     */
    protected function find(string $capability): ?Capability
    {
        foreach ($this->items as $item) {
            if ($item->matches($capability)) {
                return $item;
            }
        }

        return null;
    }
}
