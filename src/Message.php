<?php

namespace DirectoryTree\ImapEngine;

use BackedEnum;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use DirectoryTree\ImapEngine\Connection\Responses\Data\ListData;
use DirectoryTree\ImapEngine\Connection\Responses\MessageResponseParser;
use DirectoryTree\ImapEngine\Exceptions\ImapCapabilityException;
use DirectoryTree\ImapEngine\Support\Str;
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
        protected FetchedMessageData $data,
    ) {}

    /**
     * Get the names of properties that should be serialized.
     */
    public function __sleep(): array
    {
        // We don't want to serialize the parsed message.
        return ['folder', 'data'];
    }

    /**
     * Get the message's folder.
     */
    public function folder(): FolderInterface
    {
        return $this->folder;
    }

    /**
     * Get all data fetched for the message.
     */
    public function data(): FetchedMessageData
    {
        return $this->data;
    }

    /**
     * Get the message's identifier.
     */
    public function uid(): int
    {
        return $this->data->uid();
    }

    /**
     * Get the message's size in bytes (RFC822.SIZE).
     */
    public function size(): ?int
    {
        return $this->data->size();
    }

    /**
     * Get the message modification sequence.
     */
    public function modSequence(): ?int
    {
        return $this->data->modSequence();
    }

    /**
     * Get the message's flags.
     */
    public function flags(): array
    {
        return $this->data->flags();
    }

    /**
     * Get the message's raw headers.
     */
    public function head(bool $fetch = false): string
    {
        if (! $this->data->has('BODY[HEADER]') && $fetch) {
            $this->fetchHead();
        }

        return $this->data->head();
    }

    /**
     * Determine if the message has headers.
     */
    public function hasHead(): bool
    {
        return $this->head() !== '';
    }

    /**
     * Get the message's raw body.
     */
    public function body(): string
    {
        return $this->data->body();
    }

    /**
     * Determine if the message has contents.
     */
    public function hasBody(): bool
    {
        return $this->body() !== '';
    }

    /**
     * Get the message's body structure.
     */
    public function bodyStructure(bool $fetch = false): ?BodyStructureCollection
    {
        if ($this->bodyStructure) {
            return $this->bodyStructure;
        }

        if (! $this->data->has('BODYSTRUCTURE') && $fetch) {
            $this->fetchBodyStructureData();
        }

        $structure = $this->data->bodyStructure();

        if (! $tokens = $structure?->tokens()) {
            return null;
        }

        // If the first token is a list, it's a multipart message.
        return $this->bodyStructure = head($tokens) instanceof ListData
            ? BodyStructureCollection::fromListData($structure)
            : new BodyStructureCollection(parts: [BodyStructurePart::fromListData($structure)]);
    }

    /**
     * Determine if the message has body structure data.
     */
    public function hasBodyStructure(): bool
    {
        return ! is_null($this->data->bodyStructure());
    }

    /**
     * {@inheritDoc}
     */
    public function is(MessageInterface $message): bool
    {
        return $message instanceof self
            && $this->uid() === $message->uid()
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
            ->store($this->uid(), $flag, mode: $operation);

        if ($expunge) {
            $this->folder->expunge($this->uid());
        }

        $this->data = $this->data->merge([
            'FLAGS' => match ($operation) {
                '+' => array_unique(array_merge($this->flags(), [$flag])),
                '-' => array_diff($this->flags(), [$flag]),
            },
        ]);
    }

    /**
     * Copy the message to the given folder.
     */
    public function copy(string $folder): ?int
    {
        $mailbox = $this->folder->mailbox();

        if (! $mailbox->capabilities()->supports('UIDPLUS')) {
            throw new ImapCapabilityException(
                'Unable to copy message. IMAP server does not support UIDPLUS capability'
            );
        }

        $response = $mailbox->connection()->copy($this->uid(), $folder);

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

        switch (true) {
            case $mailbox->capabilities()->supports('MOVE'):
                $response = $mailbox->connection()->move($this->uid(), $folder);

                return MessageResponseParser::getUidFromCopy($response);

            case $mailbox->capabilities()->supports('UIDPLUS'):
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
    public function header(string $name, int $offset = 0, bool $fetch = false): ?IHeader
    {
        if ($fetch && ! $this->hasHead()) {
            $this->head(fetch: true);
        }

        if ($this->isEmpty()) {
            return null;
        }

        return $this->parse()->getHeader($name, $offset);
    }

    /**
     * Get the message date and time.
     */
    public function date(bool $fetch = false): ?CarbonInterface
    {
        if (! $header = $this->header(HeaderConsts::DATE, fetch: $fetch)) {
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
    public function messageId(bool $fetch = false): ?string
    {
        return $this->header(HeaderConsts::MESSAGE_ID, fetch: $fetch)?->getValue();
    }

    /**
     * Get the message's subject.
     */
    public function subject(bool $fetch = false): ?string
    {
        return $this->header(HeaderConsts::SUBJECT, fetch: $fetch)?->getValue();
    }

    /**
     * Get the FROM address.
     */
    public function from(bool $fetch = false): ?Address
    {
        return head($this->addresses(HeaderConsts::FROM, fetch: $fetch)) ?: null;
    }

    /**
     * Get the SENDER address.
     */
    public function sender(bool $fetch = false): ?Address
    {
        return head($this->addresses(HeaderConsts::SENDER, fetch: $fetch)) ?: null;
    }

    /**
     * Get the REPLY-TO address.
     */
    public function replyTo(bool $fetch = false): ?Address
    {
        return head($this->addresses(HeaderConsts::REPLY_TO, fetch: $fetch)) ?: null;
    }

    /**
     * Get the IN-REPLY-TO message identifier(s).
     *
     * @return string[]
     */
    public function inReplyTo(bool $fetch = false): array
    {
        $parts = $this->header(HeaderConsts::IN_REPLY_TO, fetch: $fetch)?->getParts() ?? [];

        $values = array_map(fn (IHeaderPart $part) => $part->getValue(), $parts);

        return array_values(array_filter($values));
    }

    /**
     * Get the TO addresses.
     *
     * @return Address[]
     */
    public function to(bool $fetch = false): array
    {
        return $this->addresses(HeaderConsts::TO, fetch: $fetch);
    }

    /**
     * Get the CC addresses.
     *
     * @return Address[]
     */
    public function cc(bool $fetch = false): array
    {
        return $this->addresses(HeaderConsts::CC, fetch: $fetch);
    }

    /**
     * Get the BCC addresses.
     *
     * @return Address[]
     */
    public function bcc(bool $fetch = false): array
    {
        return $this->addresses(HeaderConsts::BCC, fetch: $fetch);
    }

    /**
     * Get addresses from the given header.
     *
     * @return Address[]
     */
    public function addresses(string $header, bool $fetch = false): array
    {
        $parts = $this->header($header, fetch: $fetch)?->getParts() ?? [];

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
    public function text(bool $fetch = false): ?string
    {
        if ($fetch && ! $this->hasBody()) {
            if ($part = $this->bodyStructure(fetch: true)?->text()) {
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
    public function html(bool $fetch = false): ?string
    {
        if ($fetch && ! $this->hasBody()) {
            if ($part = $this->bodyStructure(fetch: true)?->html()) {
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
    public function attachments(bool $fetch = false): array
    {
        if ($fetch && ! $this->hasBody()) {
            return Attachment::lazy($this);
        }

        if ($this->isEmpty()) {
            return [];
        }

        return Attachment::parsed($this);
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
     * Fetch a specific body part by part number.
     */
    public function bodyPart(string $partNumber, bool $peek = true): ?string
    {
        $key = "BODY[$partNumber]";

        if ($peek && $this->data->has($key)) {
            return $this->data->get($key);
        }

        $response = $this->folder->mailbox()
            ->connection()
            ->fetch($this->uid(), $peek ? "BODY.PEEK[$partNumber]" : "BODY[$partNumber]");

        if (! $data = $response->messages()[0] ?? null) {
            return null;
        }

        $this->data = $this->data->merge($data);

        return $data->get($key);
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
        return $this->data->toArray();
    }

    /**
     * Get the string representation of the message.
     */
    public function __toString(): string
    {
        return implode("\r\n\r\n", array_filter([
            rtrim($this->head()),
            ltrim($this->body()),
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
     * Fetch the headers from the server.
     */
    protected function fetchHead(): ?string
    {
        $response = $this->folder
            ->mailbox()
            ->connection()
            ->fetch($this->uid(), 'BODY.PEEK[HEADER]');

        if (! $data = $response->messages()[0] ?? null) {
            return null;
        }

        $this->data = $this->data->merge($data);

        return $data->get('BODY[HEADER]');
    }

    /**
     * Fetch the body structure data from the server.
     */
    protected function fetchBodyStructureData(): ?ListData
    {
        $response = $this->folder
            ->mailbox()
            ->connection()
            ->fetch($this->uid(), 'BODYSTRUCTURE');

        if (! $data = $response->messages()[0] ?? null) {
            return null;
        }

        $this->data = $this->data->merge($data);

        return $data->bodyStructure();
    }
}
