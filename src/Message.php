<?php

namespace DirectoryTree\ImapEngine;

use BackedEnum;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use DirectoryTree\ImapEngine\Connection\Responses\Data\ListData;
use DirectoryTree\ImapEngine\Connection\Responses\MessageResponseParser;
use DirectoryTree\ImapEngine\Exceptions\ImapCapabilityException;
use DirectoryTree\ImapEngine\Support\Str;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use ZBateson\MailMimeParser\Header\DateHeader;
use ZBateson\MailMimeParser\Header\HeaderConsts;
use ZBateson\MailMimeParser\Header\IHeader;
use ZBateson\MailMimeParser\Header\IHeaderPart;
use ZBateson\MailMimeParser\Header\Part\AddressPart;
use ZBateson\MailMimeParser\Header\Part\ContainerPart;
use ZBateson\MailMimeParser\Header\Part\NameValuePart;

class Message implements Arrayable, JsonSerializable, MessageInterface
{
    use HasFlags, HasParsedMessage;

    /**
     * The parsed body structure.
     */
    protected ?BodyStructureCollection $bodyStructure = null;

    /**
     * Constructor.
     */
    public function __construct(
        protected FolderInterface $folder,
        protected int $uid,
        protected array $flags,
        protected string $head,
        protected string $body,
        protected ?int $size = null,
        protected ?ListData $bodyStructureData = null,
    ) {}

    /**
     * Get the names of properties that should be serialized.
     */
    public function __sleep(): array
    {
        // We don't want to serialize the parsed message.
        return ['folder', 'uid', 'flags', 'head', 'body', 'size'];
    }

    /**
     * Get the message's folder.
     */
    public function folder(): FolderInterface
    {
        return $this->folder;
    }

    /**
     * Get the message's identifier.
     */
    public function uid(): int
    {
        return $this->uid;
    }

    /**
     * Get the message's size in bytes (RFC822.SIZE).
     */
    public function size(): ?int
    {
        return $this->size;
    }

    /**
     * Get the message's flags.
     */
    public function flags(): array
    {
        return $this->flags;
    }

    /**
     * Get the message's raw headers.
     */
    public function head(bool $lazy = false): string
    {
        if (! $this->head && $lazy) {
            $this->head = $this->fetchHead() ?? '';
        }

        return $this->head;
    }

    /**
     * Determine if the message has headers.
     */
    public function hasHead(): bool
    {
        return ! empty($this->head);
    }

    /**
     * Fetch the headers from the server.
     */
    protected function fetchHead(): ?string
    {
        $response = $this->folder
            ->mailbox()
            ->connection()
            ->bodyHeader($this->uid);

        if ($response->isEmpty()) {
            return null;
        }

        $data = $response->first()->tokenAt(3);

        if (! $data instanceof ListData) {
            return null;
        }

        return $data->lookup('[HEADER]')?->value;
    }

    /**
     * Get the message's raw body.
     */
    public function body(): string
    {
        return $this->body;
    }

    /**
     * Determine if the message has contents.
     */
    public function hasBody(): bool
    {
        return ! empty($this->body);
    }

    /**
     * Get the message's body structure.
     */
    public function bodyStructure(bool $lazy = false): ?BodyStructureCollection
    {
        if ($this->bodyStructure) {
            return $this->bodyStructure;
        }

        if (! $this->bodyStructureData && $lazy) {
            $this->bodyStructureData = $this->fetchBodyStructureData();
        }

        if (! $tokens = $this->bodyStructureData?->tokens()) {
            return null;
        }

        // If the first token is a list, it's a multipart message.
        return $this->bodyStructure = head($tokens) instanceof ListData
            ? BodyStructureCollection::fromListData($this->bodyStructureData)
            : new BodyStructureCollection(parts: [BodyStructurePart::fromListData($this->bodyStructureData)]);
    }

    /**
     * Determine if the message has body structure data.
     */
    public function hasBodyStructure(): bool
    {
        return (bool) $this->bodyStructureData;
    }

    /**
     * {@inheritDoc}
     */
    public function is(MessageInterface $message): bool
    {
        return $message instanceof self
            && $this->uid === $message->uid
            && $this->folder->is($message->folder);
    }

    /**
     * Add or remove a flag from the message.
     */
    public function flag(BackedEnum|string $flag, string $operation, bool $expunge = false): void
    {
        $flag = Str::enum($flag);

        $this->folder->mailbox()
            ->connection()
            ->store($flag, $this->uid, mode: $operation);

        if ($expunge) {
            $this->folder->expunge();
        }

        $this->flags = match ($operation) {
            '+' => array_unique(array_merge($this->flags, [$flag])),
            '-' => array_diff($this->flags, [$flag]),
        };
    }

    /**
     * Copy the message to the given folder.
     */
    public function copy(string $folder): ?int
    {
        $mailbox = $this->folder->mailbox();

        $capabilities = $mailbox->capabilities();

        if (! in_array('UIDPLUS', $capabilities)) {
            throw new ImapCapabilityException(
                'Unable to copy message. IMAP server does not support UIDPLUS capability'
            );
        }

        $response = $mailbox->connection()->copy($folder, $this->uid);

        return MessageResponseParser::getUidFromCopy($response);
    }

    /**
     * Move the message to the given folder.
     *
     * @throws ImapCapabilityException
     */
    public function move(string $folder, bool $expunge = false): ?int
    {
        $mailbox = $this->folder->mailbox();

        $capabilities = $mailbox->capabilities();

        switch (true) {
            case in_array('MOVE', $capabilities):
                $response = $mailbox->connection()->move($folder, $this->uid);

                if ($expunge) {
                    $this->folder->expunge();
                }

                return MessageResponseParser::getUidFromCopy($response);

            case in_array('UIDPLUS', $capabilities):
                $uid = $this->copy($folder);

                $this->delete($expunge);

                return $uid;

            default:
                throw new ImapCapabilityException(
                    'Unable to move message. IMAP server does not support MOVE or UIDPLUS capabilities'
                );
        }
    }

    /**
     * Get a header from the message.
     */
    public function header(string $name, int $offset = 0, bool $lazy = false): ?IHeader
    {
        if ($lazy && ! $this->hasHead()) {
            $this->head(lazy: true);
        }

        if ($this->isEmpty()) {
            return null;
        }

        return $this->parse()->getHeader($name, $offset);
    }

    /**
     * Get the message date and time.
     */
    public function date(bool $lazy = false): ?CarbonInterface
    {
        if (! $header = $this->header(HeaderConsts::DATE, lazy: $lazy)) {
            return null;
        }

        if (! $header instanceof DateHeader) {
            return null;
        }

        if (! $date = $header->getDateTime()) {
            return null;
        }

        return Carbon::instance($date);
    }

    /**
     * Get the message's message-id.
     */
    public function messageId(bool $lazy = false): ?string
    {
        return $this->header(HeaderConsts::MESSAGE_ID, lazy: $lazy)?->getValue();
    }

    /**
     * Get the message's subject.
     */
    public function subject(bool $lazy = false): ?string
    {
        return $this->header(HeaderConsts::SUBJECT, lazy: $lazy)?->getValue();
    }

    /**
     * Get the FROM address.
     */
    public function from(bool $lazy = false): ?Address
    {
        return head($this->addresses(HeaderConsts::FROM, lazy: $lazy)) ?: null;
    }

    /**
     * Get the SENDER address.
     */
    public function sender(bool $lazy = false): ?Address
    {
        return head($this->addresses(HeaderConsts::SENDER, lazy: $lazy)) ?: null;
    }

    /**
     * Get the REPLY-TO address.
     */
    public function replyTo(bool $lazy = false): ?Address
    {
        return head($this->addresses(HeaderConsts::REPLY_TO, lazy: $lazy)) ?: null;
    }

    /**
     * Get the IN-REPLY-TO message identifier(s).
     *
     * @return string[]
     */
    public function inReplyTo(bool $lazy = false): array
    {
        $parts = $this->header(HeaderConsts::IN_REPLY_TO, lazy: $lazy)?->getParts() ?? [];

        $values = array_map(fn (IHeaderPart $part) => $part->getValue(), $parts);

        return array_values(array_filter($values));
    }

    /**
     * Get the TO addresses.
     *
     * @return Address[]
     */
    public function to(bool $lazy = false): array
    {
        return $this->addresses(HeaderConsts::TO, lazy: $lazy);
    }

    /**
     * Get the CC addresses.
     *
     * @return Address[]
     */
    public function cc(bool $lazy = false): array
    {
        return $this->addresses(HeaderConsts::CC, lazy: $lazy);
    }

    /**
     * Get the BCC addresses.
     *
     * @return Address[]
     */
    public function bcc(bool $lazy = false): array
    {
        return $this->addresses(HeaderConsts::BCC, lazy: $lazy);
    }

    /**
     * Get addresses from the given header.
     *
     * @return Address[]
     */
    public function addresses(string $header, bool $lazy = false): array
    {
        $parts = $this->header($header, lazy: $lazy)?->getParts() ?? [];

        $addresses = array_map(fn (IHeaderPart $part) => match (true) {
            $part instanceof AddressPart => new Address($part->getEmail(), $part->getName()),
            $part instanceof NameValuePart => new Address($part->getName(), $part->getValue()),
            $part instanceof ContainerPart => new Address($part->getValue(), ''),
            default => null,
        }, $parts);

        return array_filter($addresses);
    }

    /**
     * Get the message's text content.
     */
    public function text(bool $lazy = false): ?string
    {
        if ($lazy && ! $this->hasBody()) {
            if ($part = $this->bodyStructure(lazy: true)?->text()) {
                return Support\BodyPartDecoder::text($part, $this->bodyPart($part->partNumber()));
            }
        }

        if ($this->isEmpty()) {
            return null;
        }

        return $this->parse()->getTextContent();
    }

    /**
     * Get the message's HTML content.
     */
    public function html(bool $lazy = false): ?string
    {
        if ($lazy && ! $this->hasBody()) {
            if ($part = $this->bodyStructure(lazy: true)?->html()) {
                return Support\BodyPartDecoder::text($part, $this->bodyPart($part->partNumber()));
            }
        }

        if ($this->isEmpty()) {
            return null;
        }

        return $this->parse()->getHtmlContent();
    }

    /**
     * Get the message's attachments.
     *
     * @return Attachment[]
     */
    public function attachments(bool $lazy = false): array
    {
        if ($lazy && ! $this->hasBody()) {
            return $this->getLazyAttachments();
        }

        if ($this->isEmpty()) {
            return [];
        }

        return $this->getParsedAttachments();
    }

    /**
     * Get attachments from the parsed message.
     *
     * @return Attachment[]
     */
    protected function getParsedAttachments(): array
    {
        $attachments = [];

        foreach ($this->parse()->getAllAttachmentParts() as $part) {
            if ($this->isForwardedMessage($part)) {
                $attachments = array_merge($attachments, (new FileMessage($part->getContent()))->attachments());
            } else {
                $attachments[] = new Attachment(
                    $part->getFilename(),
                    $part->getContentId(),
                    $part->getContentType(),
                    $part->getContentDisposition(),
                    $part->getBinaryContentStream() ?? Utils::streamFor(''),
                );
            }
        }

        return $attachments;
    }

    /**
     * Determine if the message has attachments.
     */
    public function hasAttachments(): bool
    {
        return $this->attachmentCount() > 0;
    }

    /**
     * Get the count of attachments.
     */
    public function attachmentCount(): int
    {
        if ($this->isEmpty()) {
            return 0;
        }

        return $this->parse()->getAttachmentCount();
    }

    /**
     * Get attachments using lazy loading from body structure.
     *
     * @return Attachment[]
     */
    protected function getLazyAttachments(): array
    {
        return array_map(
            fn (BodyStructurePart $part) => new Attachment(
                $part->filename(),
                $part->id(),
                $part->contentType(),
                $part->disposition()?->type()?->value,
                new Support\LazyBodyPartStream($this, $part),
            ),
            $this->bodyStructure(lazy: true)?->attachments() ?? []
        );
    }

    /**
     * Fetch a specific body part by part number.
     */
    public function bodyPart(string $partNumber, bool $peek = true): ?string
    {
        $response = $this->folder->mailbox()
            ->connection()
            ->bodyPart($partNumber, $this->uid, $peek);

        if ($response->isEmpty()) {
            return null;
        }

        $data = $response->first()->tokenAt(3);

        if (! $data instanceof ListData) {
            return null;
        }

        return $data->lookup("[$partNumber]")?->value;
    }

    /**
     * Delete the message.
     */
    public function delete(bool $expunge = false): void
    {
        $this->markDeleted($expunge);
    }

    /**
     * Restore the message.
     */
    public function restore(): void
    {
        $this->unmarkDeleted();
    }

    /**
     * Get the array representation of the message.
     */
    public function toArray(): array
    {
        return [
            'uid' => $this->uid,
            'flags' => $this->flags,
            'head' => $this->head,
            'body' => $this->body,
            'size' => $this->size,
        ];
    }

    /**
     * Get the string representation of the message.
     */
    public function __toString(): string
    {
        return implode("\r\n\r\n", array_filter([
            rtrim($this->head),
            ltrim($this->body),
        ]));
    }

    /**
     * Get the JSON representation of the message.
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Determine if the message is empty.
     */
    public function isEmpty(): bool
    {
        return ! $this->hasHead() && ! $this->hasBody();
    }

    /**
     * Fetch the body structure data from the server.
     */
    protected function fetchBodyStructureData(): ?ListData
    {
        $response = $this->folder
            ->mailbox()
            ->connection()
            ->bodyStructure($this->uid);

        if ($response->isEmpty()) {
            return null;
        }

        $data = $response->first()->tokenAt(3);

        if (! $data instanceof ListData) {
            return null;
        }

        return $data->lookup('BODYSTRUCTURE');
    }
}
