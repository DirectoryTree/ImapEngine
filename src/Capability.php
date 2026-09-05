<?php

namespace DirectoryTree\ImapEngine;

class Capability
{
    /**
     * Constructor.
     */
    public function __construct(
        protected string $name,
        protected bool $enabled = false,
    ) {
        $this->name = strtoupper($name);
    }

    /**
     * Get the capability name.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Determine if the capability matches the given name.
     */
    public function matches(string $capability): bool
    {
        $capability = strtoupper($capability);

        return $this->name === $capability
            || str_starts_with($this->name, "{$capability}=");
    }

    /**
     * Determine if the capability is enabled.
     */
    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Enable the capability.
     */
    public function enable(): static
    {
        $this->enabled = true;

        return $this;
    }
}
