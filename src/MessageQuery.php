<?php

namespace DirectoryTree\ImapEngine;

use DirectoryTree\ImapEngine\Collections\MessageCollection;
use DirectoryTree\ImapEngine\Collections\ResponseCollection;
use DirectoryTree\ImapEngine\Connection\ConnectionInterface;
use DirectoryTree\ImapEngine\Connection\ImapQueryBuilder;
use DirectoryTree\ImapEngine\Connection\Responses\MessageResponseParser;
use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
use DirectoryTree\ImapEngine\Connection\Tokens\Atom;
use DirectoryTree\ImapEngine\Enums\ImapFetchIdentifier;
use DirectoryTree\ImapEngine\Enums\ImapFlag;
use DirectoryTree\ImapEngine\Exceptions\ImapCommandException;
use DirectoryTree\ImapEngine\Pagination\LengthAwarePaginator;
use DirectoryTree\ImapEngine\Support\Str;
use Illuminate\Support\Collection;
use Illuminate\Support\ItemNotFoundException;

/**
 * @mixin \DirectoryTree\ImapEngine\Connection\ImapQueryBuilder
 */
class MessageQuery implements MessageQueryInterface
{
    use QueriesMessages;

    /**
     * Constructor.
     */
    public function __construct(
        protected FolderInterface $folder,
        protected ImapQueryBuilder $query,
    ) {}

    /**
     * Count all available messages matching the current search criteria.
     */
    public function count(): int
    {
        return $this->search()->count();
    }

    /**
     * Get the first message in the resulting collection.
     *
     * @return Message|null
     */
    public function first(): ?MessageInterface
    {
        try {
            return $this->firstOrFail();
        } catch (ItemNotFoundException) {
            return null;
        }
    }

    /**
     * Get the first message in the resulting collection or throw an exception.
     *
     * @return Message
     */
    public function firstOrFail(): MessageInterface
    {
        return $this->limit(1)->get()->firstOrFail();
    }

    /**
     * Get the messages matching the current query.
     */
    public function get(): MessageCollection
    {
        return $this->process($this->search());
    }

    /**
     * Append a new message to the folder.
     */
    public function append(string $message, mixed $flags = null): int
    {
        $response = $this->connection()->append(
            $this->folder->path(), $message, Str::enums($flags),
        );

        return $response // TAG4 OK [APPENDUID <uidvalidity> <uid>] APPEND completed.
            ->tokenAt(2) // [APPENDUID <uidvalidity> <uid>]
            ->tokenAt(2) // <uid>
            ->value;
    }

    /**
     * Execute a callback over each message via a chunked query.
     */
    public function each(callable $callback, int $chunkSize = 10, int $startChunk = 1): void
    {
        $this->chunk(function (MessageCollection $messages) use ($callback) {
            foreach ($messages as $key => $message) {
                if ($callback($message, $key) === false) {
                    return false;
                }
            }
        }, $chunkSize, $startChunk);
    }

    /**
     * Execute a callback over each chunk of messages.
     */
    public function chunk(callable $callback, int $chunkSize = 10, int $startChunk = 1): void
    {
        $startChunk = max($startChunk, 1);
        $chunkSize = max($chunkSize, 1);

        // Get all search result tokens once.
        $messages = $this->search();

        // Calculate how many chunks there are
        $totalChunks = (int) ceil($messages->count() / $chunkSize);

        // If startChunk is beyond our total chunks, return early.
        if ($startChunk > $totalChunks) {
            return;
        }

        // Save previous state to restore later.
        $previousLimit = $this->limit;
        $previousPage = $this->page;

        $this->limit = $chunkSize;

        // Iterate from the starting chunk to the last chunk.
        for ($page = $startChunk; $page <= $totalChunks; $page++) {
            $this->page = $page;

            // populate() will use $this->page to slice the results.
            $hydrated = $this->populate($messages);

            // If no messages are returned, break out to prevent infinite loop.
            if ($hydrated->isEmpty()) {
                break;
            }

            // If the callback returns false, break out.
            if ($callback($hydrated, $page) === false) {
                break;
            }
        }

        // Restore the original state.
        $this->limit = $previousLimit;
        $this->page = $previousPage;
    }

    /**
     * Paginate the current query.
     */
    public function paginate(int $perPage = 5, $page = null, string $pageName = 'page'): LengthAwarePaginator
    {
        if (is_null($page) && isset($_GET[$pageName]) && $_GET[$pageName] > 0) {
            $this->page = intval($_GET[$pageName]);
        } elseif ($page > 0) {
            $this->page = (int) $page;
        }

        $this->limit = $perPage;

        return $this->get()->paginate($perPage, $this->page, $pageName, true);
    }

    /**
     * Find a message by the given identifier type or throw an exception.
     *
     * @return Message
     */
    public function findOrFail(int $id, ImapFetchIdentifier $identifier = ImapFetchIdentifier::Uid): MessageInterface
    {
        /** @var UntaggedResponse $response */
        $response = $this->id($id, $identifier)->firstOrFail();

        $uid = $response->tokenAt(3) // ListData
            ->tokenAt(1) // Atom
            ->value; // UID

        return $this->process(new MessageCollection([$uid]))->firstOrFail();
    }

    /**
     * Find a message by the given identifier type.
     *
     * @return Message|null
     */
    public function find(int $id, ImapFetchIdentifier $identifier = ImapFetchIdentifier::Uid): ?MessageInterface
    {
        /** @var UntaggedResponse $response */
        if (! $response = $this->id($id, $identifier)->first()) {
            return null;
        }

        $uid = $response->tokenAt(3) // ListData
            ->tokenAt(1) // Atom
            ->value; // UID

        return $this->process(new MessageCollection([$uid]))->first();
    }

    /**
     * Destroy the given messages.
     */
    public function destroy(array|int $uids, bool $expunge = false): void
    {
        $uids = (array) $uids;

        $this->folder->mailbox()
            ->connection()
            ->store([ImapFlag::Deleted->value], $uids, mode: '+');

        if ($expunge) {
            $this->folder->expunge();
        }
    }

    /**
     * Process the collection of messages.
     */
    protected function process(Collection $messages): MessageCollection
    {
        if ($messages->isNotEmpty()) {
            return $this->populate($messages);
        }

        return MessageCollection::make();
    }

    /**
     * Populate a given id collection and receive a fully fetched message collection.
     */
    protected function populate(Collection $uids): MessageCollection
    {
        $messages = MessageCollection::make();

        $messages->total($uids->count());

        $rawMessages = $this->fetch($uids);

        foreach ($rawMessages['uids'] as $uid) {
            $flags = $rawMessages['flags'][$uid] ?? [];
            $headers = $rawMessages['headers'][$uid] ?? '';
            $contents = $rawMessages['contents'][$uid] ?? '';

            $messages->push(
                $this->newMessage($uid, $flags, $headers, $contents)
            );
        }

        return $messages;
    }

    /**
     * Fetch a given id collection.
     */
    protected function fetch(Collection $messages): array
    {
        if ($this->fetchOrder === 'desc') {
            $messages = $messages->reverse();
        }

        $uids = $messages->forPage($this->page, $this->limit)->toArray();

        $flags = $this->fetchFlags ? $this->connection()
            ->flags($uids)
            ->mapWithKeys(MessageResponseParser::getFlags(...))->all() : [];

        $headers = $this->fetchHeaders ? $this->connection()
            ->bodyHeader($uids, $this->fetchAsUnread)
            ->mapWithKeys(MessageResponseParser::getBodyHeader(...))->all() : [];

        $contents = $this->fetchBody ? $this->connection()
            ->bodyText($uids, $this->fetchAsUnread)
            ->mapWithKeys(MessageResponseParser::getBodyText(...))->all() : [];

        return [
            'uids' => $uids,
            'flags' => $flags,
            'headers' => $headers,
            'contents' => $contents,
        ];
    }

    /**
     * Execute an IMAP search request.
     */
    protected function search(): Collection
    {
        // If the query is empty, default to fetching all.
        if ($this->query->isEmpty()) {
            $this->query->all();
        }

        $response = $this->connection()->search([
            $this->query->toImap(),
        ]);

        return new Collection(array_map(
            fn (Atom $token) => $token->value,
            $response->tokensAfter(2)
        ));
    }

    /**
     * Get the UID for the given identifier.
     */
    protected function id(int $id, ImapFetchIdentifier $identifier = ImapFetchIdentifier::Uid): ResponseCollection
    {
        try {
            return $this->connection()->uid([$id], $identifier);
        } catch (ImapCommandException $e) {
            // IMAP servers may return an error if the message number is not found.
            // If the identifier being used is a message number, and the message
            // number is in the command tokens, we can assume this has occurred
            // and safely ignore the error and return an empty collection.
            if (
                $identifier === ImapFetchIdentifier::MessageNumber
                && in_array($id, $e->command()->tokens())
            ) {
                return ResponseCollection::make();
            }

            // Otherwise, re-throw the exception.
            throw $e;
        }
    }

    /**
     * Make a new message from given raw components.
     */
    protected function newMessage(int $uid, array $flags, string $headers, string $contents): Message
    {
        return new Message($this->folder, $uid, $flags, $headers, $contents);
    }

    /**
     * Get the connection instance.
     */
    protected function connection(): ConnectionInterface
    {
        return $this->folder->mailbox()->connection();
    }
}
