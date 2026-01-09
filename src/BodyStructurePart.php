<?php

namespace DirectoryTree\ImapEngine;

use DirectoryTree\ImapEngine\Connection\Responses\Data\ListData;
use DirectoryTree\ImapEngine\Connection\Tokens\Nil;
use DirectoryTree\ImapEngine\Connection\Tokens\Token;
use DirectoryTree\ImapEngine\Enums\ContentDispositionType;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

class BodyStructurePart implements Arrayable, JsonSerializable
{
    /**
     * Constructor.
     */
    public function __construct(
        protected string $partNumber,
        protected string $type,
        protected string $subtype,
        protected array $parameters = [],
        protected ?string $id = null,
        protected ?string $description = null,
        protected ?string $encoding = null,
        protected ?int $size = null,
        protected ?int $lines = null,
        protected ?ContentDisposition $disposition = null,
    ) {}

    /**
     * Parse a single part BODYSTRUCTURE ListData into a BodyStructurePart.
     */
    public static function fromListData(ListData $data, string $partNumber = '1'): static
    {
        return static::parse($data->tokens(), $partNumber);
    }

    /**
     * Parse a single (non-multipart) part.
     *
     * @param  array<Token|ListData>  $tokens
     */
    protected static function parse(array $tokens, string $partNumber): static
    {
        return new static(
            partNumber: $partNumber,
            type: isset($tokens[0]) ? strtolower(static::tokenValue($tokens[0])) : 'text',
            subtype: isset($tokens[1]) ? strtolower(static::tokenValue($tokens[1])) : 'plain',
            parameters: isset($tokens[2]) && $tokens[2] instanceof ListData ? static::parseParameters($tokens[2]) : [],
            id: isset($tokens[3]) ? static::nullableTokenValue($tokens[3]) : null,
            description: isset($tokens[4]) ? static::nullableTokenValue($tokens[4]) : null,
            encoding: isset($tokens[5]) ? static::nullableTokenValue($tokens[5]) : null,
            size: isset($tokens[6]) ? static::intTokenValue($tokens[6]) : null,
            lines: isset($tokens[7]) ? static::intTokenValue($tokens[7]) : null,
            disposition: static::parseDisposition($tokens),
        );
    }

    /**
     * Parse the disposition from tokens.
     *
     * @param  array<Token|ListData>  $tokens
     */
    protected static function parseDisposition(array $tokens): ?ContentDisposition
    {
        for ($i = 8; $i < count($tokens); $i++) {
            if (! $tokens[$i] instanceof ListData) {
                continue;
            }

            $innerTokens = $tokens[$i]->tokens();

            if (! isset($innerTokens[0]) || ! $innerTokens[0] instanceof Token) {
                continue;
            }

            if (! $type = ContentDispositionType::tryFrom(strtolower($innerTokens[0]->value))) {
                continue;
            }

            $parameters = isset($innerTokens[1]) && $innerTokens[1] instanceof ListData
                ? static::parseParameters($innerTokens[1])
                : [];

            return new ContentDisposition($type, $parameters);
        }

        return null;
    }

    /**
     * Parse parameters from a ListData.
     */
    public static function parseParameters(ListData $data): array
    {
        $tokens = $data->tokens();

        $parameters = [];

        for ($i = 0; $i < count($tokens) - 1; $i += 2) {
            $key = strtolower(static::tokenValue($tokens[$i]));

            $parameters[$key] = static::tokenValue($tokens[$i + 1]);
        }

        return $parameters;
    }

    /**
     * Get the string value of a token.
     */
    protected static function tokenValue(Token|ListData $token): string
    {
        if ($token instanceof ListData) {
            return '';
        }

        return trim($token->value, '"');
    }

    /**
     * Get a nullable string value from a token.
     */
    protected static function nullableTokenValue(Token|ListData $token): ?string
    {
        if ($token instanceof ListData || $token instanceof Nil) {
            return null;
        }

        return trim($token->value, '"');
    }

    /**
     * Get an integer value from a token.
     */
    protected static function intTokenValue(Token|ListData $token): ?int
    {
        if ($token instanceof ListData || $token instanceof Nil) {
            return null;
        }

        return (int) $token->value;
    }

    /**
     * Get the part number (e.g., "1", "1.2", "2.1.3").
     */
    public function partNumber(): string
    {
        return $this->partNumber;
    }

    /**
     * Get the MIME type (e.g., "text", "image", "multipart").
     */
    public function type(): string
    {
        return $this->type;
    }

    /**
     * Get the MIME subtype (e.g., "plain", "html", "jpeg", "mixed").
     */
    public function subtype(): string
    {
        return $this->subtype;
    }

    /**
     * Get the full content type (e.g., "text/plain", "multipart/alternative").
     */
    public function contentType(): string
    {
        return "{$this->type}/{$this->subtype}";
    }

    /**
     * Get the parameters (e.g., charset, boundary).
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
     * Get the content ID.
     */
    public function id(): ?string
    {
        return $this->id;
    }

    /**
     * Get the content description.
     */
    public function description(): ?string
    {
        return $this->description;
    }

    /**
     * Get the content transfer encoding.
     */
    public function encoding(): ?string
    {
        return $this->encoding;
    }

    /**
     * Get the size in bytes.
     */
    public function size(): ?int
    {
        return $this->size;
    }

    /**
     * Get the number of lines (for text parts).
     */
    public function lines(): ?int
    {
        return $this->lines;
    }

    /**
     * Get the content disposition.
     */
    public function disposition(): ?ContentDisposition
    {
        return $this->disposition;
    }

    /**
     * Get the filename from disposition parameters.
     */
    public function filename(): ?string
    {
        return $this->disposition?->filename() ?? $this->parameters['name'] ?? null;
    }

    /**
     * Get the charset from parameters.
     */
    public function charset(): ?string
    {
        return $this->parameters['charset'] ?? null;
    }

    /**
     * Determine if this is a text part.
     */
    public function isText(): bool
    {
        return $this->type === 'text' && $this->subtype === 'plain';
    }

    /**
     * Determine if this is an HTML part.
     */
    public function isHtml(): bool
    {
        return $this->type === 'text' && $this->subtype === 'html';
    }

    /**
     * Determine if this is an attachment.
     */
    public function isAttachment(): bool
    {
        if ($this->disposition?->isAttachment()) {
            return true;
        }

        // Inline parts are not attachments.
        if ($this->disposition?->isInline()) {
            return false;
        }

        // Consider non-text/html parts with filenames as attachments.
        if ($this->filename() && ! $this->isText() && ! $this->isHtml()) {
            return true;
        }

        return false;
    }

    /**
     * Determine if this is an inline part.
     */
    public function isInline(): bool
    {
        return $this->disposition?->isInline() ?? false;
    }

    /**
     * Get the array representation.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'size' => $this->size,
            'lines' => $this->lines,
            'subtype' => $this->subtype,
            'encoding' => $this->encoding,
            'parameters' => $this->parameters,
            'part_number' => $this->partNumber,
            'description' => $this->description,
            'content_type' => $this->contentType(),
            'disposition' => $this->disposition?->toArray(),
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
