<?php

namespace DirectoryTree\ImapEngine;

use DirectoryTree\ImapEngine\Enums\ContentDispositionType;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * @see https://datatracker.ietf.org/doc/html/rfc2183
 */
class ContentDisposition implements Arrayable, JsonSerializable
{
    /**
     * Constructor.
     */
    public function __construct(
        protected ContentDispositionType $type,
        protected array $parameters = [],
    ) {}

    /**
     * Get the disposition type.
     */
    public function type(): ContentDispositionType
    {
        return $this->type;
    }

    /**
     * Get the disposition parameters.
     */
    public function parameters(): array
    {
        return $this->parameters;
    }

    /**
     * Get a specific parameter value.
     */
    public function parameter(string $name): ?string
    {
        return $this->parameters[strtolower($name)] ?? null;
    }

    /**
     * Get the filename parameter.
     */
    public function filename(): ?string
    {
        return $this->parameters['filename'] ?? null;
    }

    /**
     * Determine if this is an attachment disposition.
     */
    public function isAttachment(): bool
    {
        return $this->type === ContentDispositionType::Attachment;
    }

    /**
     * Determine if this is an inline disposition.
     */
    public function isInline(): bool
    {
        return $this->type === ContentDispositionType::Inline;
    }

    /**
     * Get the array representation.
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'parameters' => $this->parameters,
        ];
    }

    /**
     * Get the JSON representation.
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
