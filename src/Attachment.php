<?php

namespace DirectoryTree\ImapEngine;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\Mime\MimeTypes;

class Attachment implements Arrayable, JsonSerializable
{
    /**
     * Constructor.
     */
    public function __construct(
        protected string $filename,
        protected string $contentType,
        protected StreamInterface $contentStream,
    ) {}

    /**
     * Get the attachment's filename.
     */
    public function filename(): string
    {
        return $this->filename;
    }

    /**
     * Get the attachment's content type.
     */
    public function contentType(): string
    {
        return $this->contentType;
    }

    /**
     * Get the attachment's contents.
     */
    public function contents(): string
    {
        return $this->contentStream->getContents();
    }

    /**
     * Get the attachment's content stream.
     */
    public function contentStream(): StreamInterface
    {
        return $this->contentStream;
    }

    /**
     * Save the attachment to a file.
     */
    public function save(string $path): false|int
    {
        return file_put_contents($path, $this->contents());
    }

    /**
     * Get the attachment's extension.
     */
    public function extension(): ?string
    {
        if ($ext = pathinfo($this->filename, PATHINFO_EXTENSION)) {
            return $ext;
        }

        if ($ext = (MimeTypes::getDefault()->getExtensions($this->contentType)[0] ?? null)) {
            return $ext;
        }

        return null;
    }

    /**
     * Get the array representation of the attachment.
     */
    public function toArray(): array
    {
        return [
            'filename' => $this->filename,
            'contentType' => $this->contentType,
            'contents' => $this->contents(),
        ];
    }

    /**
     * Get the JSON representation of the attachment.
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
