<?php

namespace DirectoryTree\ImapEngine;

class Capabilities
{
    /**
     * The capabilities.
     *
     * @var array<string, Capability>
     */
    protected array $items = [];

    /**
     * Create a capability collection from the given values.
     */
    public static function from(iterable $capabilities): static
    {
        $instance = new static;

        foreach ($capabilities as $capability) {
            $item = new Capability($capability);

            $instance->items[$item->name()] = $item;
        }

        return $instance;
    }

    /**
     * Get all supported capabilities.
     */
    public function all(): array
    {
        return array_keys($this->items);
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
     * Mark the given capabilities as enabled.
     */
    public function enable(string ...$capabilities): void
    {
        foreach ($capabilities as $capability) {
            ($this->items[strtoupper($capability)] ?? null)?->enable();
        }
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
