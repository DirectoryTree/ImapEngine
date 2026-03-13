<?php

namespace DirectoryTree\ImapEngine\Support;

use DirectoryTree\ImapEngine\BodyStructurePart;
use DirectoryTree\ImapEngine\Message;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

class LazyBodyPartStream implements StreamInterface
{
    /**
     * The current position in the stream.
     */
    protected int $position = 0;

    /**
     * The cached content.
     */
    protected ?string $content = null;

    /**
     * Constructor.
     */
    public function __construct(
        protected Message $message,
        protected BodyStructurePart $part,
    ) {}

    /**
     * Fetch the content from the server if not already cached.
     */
    protected function fetchContent(): string
    {
        if ($this->content === null) {
            $this->content = BodyPartDecoder::binary(
                $this->part,
                $this->message->bodyPart($this->part->partNumber())
            ) ?? '';
        }

        return $this->content;
    }

    /**
     * {@inheritDoc}
     */
    public function __toString(): string
    {
        return $this->getContents();
    }

    /**
     * {@inheritDoc}
     */
    public function close(): void
    {
        $this->content = null;
        $this->position = 0;
    }

    /**
     * {@inheritDoc}
     */
    public function detach()
    {
        $this->close();

        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function getSize(): ?int
    {
        return strlen($this->fetchContent());
    }

    /**
     * {@inheritDoc}
     */
    public function tell(): int
    {
        return $this->position;
    }

    /**
     * {@inheritDoc}
     */
    public function eof(): bool
    {
        return $this->position >= strlen($this->fetchContent());
    }

    /**
     * {@inheritDoc}
     */
    public function isSeekable(): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $content = $this->fetchContent();
        $size = strlen($content);

        $this->position = match ($whence) {
            SEEK_SET => $offset,
            SEEK_CUR => $this->position + $offset,
            SEEK_END => $size + $offset,
            default => throw new RuntimeException('Invalid whence'),
        };

        if ($this->position < 0) {
            $this->position = 0;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function rewind(): void
    {
        $this->position = 0;
    }

    /**
     * {@inheritDoc}
     */
    public function isWritable(): bool
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function isReadable(): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function read(int $length): string
    {
        $content = $this->fetchContent();

        $result = substr($content, $this->position, $length);

        $this->position += strlen($result);

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function getContents(): string
    {
        $content = $this->fetchContent();

        $result = substr($content, $this->position);

        $this->position = strlen($content);

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function getMetadata(?string $key = null): mixed
    {
        return $key === null ? [] : null;
    }

    /**
     * {@inheritDoc}
     */
    public function write(string $string): int
    {
        throw new RuntimeException('Stream is not writable');
    }
}
