<?php

namespace DirectoryTree\ImapEngine\Support;

use DirectoryTree\ImapEngine\BodyStructurePart;
use DirectoryTree\ImapEngine\Message;
use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

class LazyBodyPartStream implements StreamInterface
{
    /**
     * The decoded content, spilling to disk beyond two megabytes.
     *
     * @var resource|null
     */
    protected $buffer = null;

    /**
     * The transfer decoder, retaining state across chunks.
     *
     * @var resource|null
     */
    protected $decoder = null;

    /**
     * The current read position in the decoded buffer, in bytes.
     */
    protected int $position = 0;

    /**
     * The transfer-encoded byte offset of the next partial fetch.
     */
    protected int $offset = 0;

    /**
     * The number of decoded bytes currently available in the buffer.
     */
    protected int $size = 0;

    /**
     * Whether the entire body part has been fetched and decoded.
     */
    protected bool $complete = false;

    /**
     * Whether the stream has been closed.
     */
    protected bool $closed = false;

    /**
     * Constructor.
     */
    public function __construct(
        protected Message $message,
        protected BodyStructurePart $part,
        protected int $chunkSize = 65536,
    ) {
        if ($chunkSize < 1) {
            throw new InvalidArgumentException('The chunk size must be positive.');
        }
    }

    /**
     * Close the stream.
     */
    public function __destruct()
    {
        $this->close();
    }

    /**
     * {@inheritDoc}
     */
    public function __toString(): string
    {
        $this->rewind();

        return $this->getContents();
    }

    /**
     * {@inheritDoc}
     */
    public function close(): void
    {
        if (is_resource($this->buffer)) {
            fclose($this->buffer);
        }

        $this->buffer = null;
        $this->decoder = null;
        $this->closed = true;
    }

    /**
     * {@inheritDoc}
     */
    public function detach(): null
    {
        $this->close();

        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function getSize(): ?int
    {
        return $this->complete ? $this->size : null;
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
        return $this->complete && $this->position >= $this->size;
    }

    /**
     * {@inheritDoc}
     */
    public function isSeekable(): bool
    {
        return ! $this->closed;
    }

    /**
     * {@inheritDoc}
     */
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        if ($whence === SEEK_END) {
            $this->fill();
        }

        $position = match ($whence) {
            SEEK_SET => $offset,
            SEEK_CUR => $this->position + $offset,
            SEEK_END => $this->size + $offset,
            default => throw new RuntimeException('Invalid whence'),
        };

        if ($position < 0) {
            throw new RuntimeException('Cannot seek before the beginning of the stream.');
        }

        $this->fill($position);
        $this->position = $position;
    }

    /**
     * {@inheritDoc}
     */
    public function rewind(): void
    {
        $this->seek(0);
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
        return ! $this->closed;
    }

    /**
     * {@inheritDoc}
     */
    public function read(int $length): string
    {
        if ($length < 0) {
            throw new RuntimeException('The read length must not be negative.');
        }

        $this->fill($this->position + $length);

        if ($length === 0 || $this->position >= $this->size) {
            return '';
        }

        fseek($this->buffer, $this->position);
        $content = fread($this->buffer, min($length, $this->size - $this->position));
        $this->position += strlen($content);

        return $content;
    }

    /**
     * {@inheritDoc}
     */
    public function getContents(): string
    {
        $this->fill();

        return $this->read(max(0, $this->size - $this->position));
    }

    /**
     * {@inheritDoc}
     */
    public function getMetadata(?string $key = null): mixed
    {
        return is_null($key) ? [] : null;
    }

    /**
     * {@inheritDoc}
     */
    public function write(string $string): int
    {
        throw new RuntimeException('Stream is not writable');
    }

    /**
     * Fetch enough encoded bytes to satisfy a decoded read or seek.
     */
    protected function fill(?int $length = null): void
    {
        if ($this->closed) {
            throw new RuntimeException('Stream is closed.');
        }

        while (! $this->complete && ($length === null || $this->size < $length)) {
            $this->fetch();
        }
    }

    /**
     * Append a partial response through a stateful transfer decoder.
     */
    protected function fetch(): void
    {
        $content = $this->message->bodyPart(
            $this->part->partNumber(), offset: $this->offset, length: $this->chunkSize,
        );

        if ($content === null) {
            throw new RuntimeException('The body part is no longer available.');
        }

        if ($this->buffer === null) {
            $this->buffer = fopen('php://temp/maxmemory:2097152', 'w+b');

            $filter = match (strtolower($this->part->encoding() ?? '')) {
                'base64' => 'convert.base64-decode',
                'quoted-printable' => 'convert.quoted-printable-decode',
                default => null,
            };

            if ($filter) {
                $this->decoder = stream_filter_append($this->buffer, $filter, STREAM_FILTER_WRITE);
            }
        }

        fseek($this->buffer, 0, SEEK_END);
        fwrite($this->buffer, $content);

        $this->offset += strlen($content);
        $this->complete = strlen($content) < $this->chunkSize;

        if ($this->complete && $this->decoder !== null) {
            stream_filter_remove($this->decoder);
            $this->decoder = null;
        }

        $this->size = fstat($this->buffer)['size'];
    }
}
