<?php

namespace DirectoryTree\ImapEngine;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

class Address implements Arrayable, JsonSerializable
{
    /**
     * Constructor.
     */
    public function __construct(
        protected string $email,
        protected string $name,
    ) {}

    /**
     * Get the address's email.
     */
    public function email(): string
    {
        return $this->email;
    }

    /**
     * Get the address's name.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Get the array representation of the address.
     */
    public function toArray(): array
    {
        return [
            'email' => $this->email,
            'name' => $this->name,
        ];
    }

    /**
     * Get the JSON representation of the address.
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
