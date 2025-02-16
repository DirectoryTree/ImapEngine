<?php

namespace DirectoryTree\ImapEngine;

use DirectoryTree\ImapEngine\Collections\MessageCollection;
use DirectoryTree\ImapEngine\Collections\ResponseCollection;
use DirectoryTree\ImapEngine\Connection\ConnectionInterface;
use DirectoryTree\ImapEngine\Connection\ImapQueryBuilder;
use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
use DirectoryTree\ImapEngine\Connection\Tokens\Atom;
use DirectoryTree\ImapEngine\Enums\ImapFetchIdentifier;
use DirectoryTree\ImapEngine\Pagination\LengthAwarePaginator;
use DirectoryTree\ImapEngine\Support\ForwardsCalls;
use DirectoryTree\ImapEngine\Support\Str;
use Illuminate\Support\Collection;
use Illuminate\Support\Traits\Conditionable;

/**
 * @mixin \DirectoryTree\ImapEngine\Connection\ImapQueryBuilder
 */
class MessageQuery
{
    use Conditionable, ForwardsCalls;

    /**
     * The current page.
     */
    protected int $page = 1;

    /**
     * The fetch limit.
     */
    protected ?int $limit = null;

    /**
     * Whether to fetch the message body.
     */
    protected bool $fetchBody = false;

    /**
     * Whether to fetch the message flags.
     */
    protected bool $fetchFlags = false;

    /**
     * Whether to fetch the message headers.
     */
    protected bool $fetchHeaders = false;

    /**
     * The fetch order.
     */
    protected string $fetchOrder = 'desc';

    /**
     * Whether to leave messages fetched as unread by default.
     */
    protected bool $fetchAsUnread = true;

    /**
     * The methods that should be returned from query builder.
     */
    protected array $passthru = ['toimap', 'isempty'];

    /**
     * Constructor.
     */
    public function __construct(
        protected Folder $folder,
        protected ImapQueryBuilder $query,
    ) {}

    /**
     * Handle dynamic method calls into the query builder.
     */
    public function __call(string $method, array $parameters): mixed
    {
        if (in_array(strtolower($method), $this->passthru)) {
            return $this->query->{$method}(...$parameters);
        }

        $this->forwardCallTo($this->query, $method, $parameters);

        return $this;
    }

    /**
     * Don't mark messages as read when fetching.
     */
    public function leaveUnread(): static
    {
        $this->fetchAsUnread = true;

        return $this;
    }

    /**
     * Mark all messages as read when fetching.
     */
    public function markAsRead(): static
    {
        $this->fetchAsUnread = false;

        return $this;
    }

    /**
     * Set the limit and page for the current query.
     */
    public function limit(int $limit, int $page = 1): static
    {
        if ($page >= 1) {
            $this->page = $page;
        }

        $this->limit = $limit;

        return $this;
    }

    /**
     * Get the set fetch limit.
     */
    public function getLimit(): ?int
    {
        return $this->limit;
    }

    /**
     * Set the fetch limit.
     */
    public function setLimit(int $limit): static
    {
        $this->limit = max($limit, 1);

        return $this;
    }

    /**
     * Get the set page.
     */
    public function getPage(): int
    {
        return $this->page;
    }

    /**
     * Set the page.
     */
    public function setPage(int $page): static
    {
        $this->page = $page;

        return $this;
    }

    /**
     * Determine if the body of messages is being fetched.
     */
    public function isFetchingBody(): bool
    {
        return $this->fetchBody;
    }

    /**
     * Determine if the flags of messages is being fetched.
     */
    public function isFetchingFlags(): bool
    {
        return $this->fetchFlags;
    }

    /**
     * Determine if the headers of messages is being fetched.
     */
    public function isFetchingHeaders(): bool
    {
        return $this->fetchHeaders;
    }

    /**
     * Fetch the body of messages.
     */
    public function withFlags(): static
    {
        return $this->setFetchFlags(true);
    }

    /**
     * Fetch the body of messages.
     */
    public function withBody(): static
    {
        return $this->setFetchBody(true);
    }

    /**
     * Fetch the body of messages.
     */
    public function withHeaders(): static
    {
        return $this->setFetchHeaders(true);
    }

    /**
     * Don't fetch the body of messages.
     */
    public function withoutBody(): static
    {
        return $this->setFetchBody(false);
    }

    /**
     * Don't fetch the body of messages.
     */
    public function withoutHeaders(): static
    {
        return $this->setFetchHeaders(false);
    }

    /**
     * Don't fetch the body of messages.
     */
    public function withoutFlags(): static
    {
        return $this->setFetchFlags(false);
    }

    /**
     * Set whether to fetch the flags.
     */
    protected function setFetchFlags(bool $fetchFlags): static
    {
        $this->fetchFlags = $fetchFlags;

        return $this;
    }

    /**
     * Set the fetch body flag.
     */
    protected function setFetchBody(bool $fetchBody): static
    {
        $this->fetchBody = $fetchBody;

        return $this;
    }

    /**
     * Set whether to fetch the headers.
     */
    protected function setFetchHeaders(bool $fetchHeaders): static
    {
        $this->fetchHeaders = $fetchHeaders;

        return $this;
    }

    /**
     * Set the fetch order.
     */
    public function setFetchOrder(string $fetchOrder): static
    {
        $fetchOrder = strtolower($fetchOrder);

        if (in_array($fetchOrder, ['asc', 'desc'])) {
            $this->fetchOrder = $fetchOrder;
        }

        return $this;
    }

    /**
     * Get the fetch order.
     */
    public function getFetchOrder(): string
    {
        return $this->fetchOrder;
    }

    /**
     * Set the fetch order to 'ascending'.
     */
    public function setFetchOrderAsc(): static
    {
        return $this->setFetchOrder('asc');
    }

    /**
     * Set the fetch order to 'descending'.
     */
    public function setFetchOrderDesc(): static
    {
        return $this->setFetchOrder('desc');
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
     * Count all available messages matching the current search criteria.
     */
    public function count(): int
    {
        return $this->search()->count();
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
            ->mapWithKeys(function (UntaggedResponse $response) {
                $data = $response->tokenAt(3);

                $uid = $data->lookup('UID')->value;
                $flags = $data->lookup('FLAGS')->values();

                return [$uid => $flags];
            }) : new Collection;

        $headers = $this->fetchHeaders ? $this->connection()
            ->bodyHeader($uids, $this->fetchAsUnread)
            ->mapWithKeys(function (UntaggedResponse $response) {
                $data = $response->tokenAt(3);

                $uid = $data->lookup('UID')->value;
                $headers = $data->lookup('[HEADER]')->value;

                return [$uid => $headers];
            }) : new Collection;

        $contents = $this->fetchBody ? $this->connection()
            ->bodyText($uids, $this->fetchAsUnread)
            ->mapWithKeys(function (UntaggedResponse $response) {
                $data = $response->tokenAt(3);

                $uid = $data->lookup('UID')->value;
                $contents = $data->lookup('[TEXT]')->value;

                return [$uid => $contents];
            }) : new Collection;

        return [
            'uids' => $uids,
            'flags' => $flags,
            'headers' => $headers,
            'contents' => $contents,
        ];
    }

    /**
     * Make a new message from given raw components.
     */
    protected function newMessage(int $uid, array $flags, string $headers, string $contents): Message
    {
        return new Message(
            $this->folder,
            $uid,
            $flags,
            $headers,
            $contents,
        );
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
     * Get the first message in the resulting collection.
     */
    public function first(): ?Message
    {
        return $this->limit(1)->get()->first();
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
        $result = $this->connection()->append(
            $this->folder->path(), $message, Str::enums($flags),
        );

        return $result // TAG4 OK [APPENDUID <uidvalidity> <uid>] APPEND completed.
            ->tokenAt(2) // [APPENDUID <uidvalidity> <uid>]
            ->tokenAt(2) // <uid>
            ->value;
    }

    /**
     * Execute a callback over each message via a chunked query.
     */
    public function each(callable $callback, int $chunkSize = 10, int $startChunk = 1): void
    {
        $this->chunk(fn (MessageCollection $messages) => (
            $messages->each($callback)
        ), $chunkSize, $startChunk);
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

            $callback($hydrated, $page);
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
     */
    public function findOrFail(int $id, ImapFetchIdentifier $identifier = ImapFetchIdentifier::Uid)
    {
        $uid = $this->uid($id, $identifier)
            ->firstOrFail() // Untagged response
            ->tokenAt(3) // ListData
            ->tokenAt(1) // Atom
            ->value; // UID

        return $this->process(new MessageCollection([$uid]))->firstOrFail();
    }

    /**
     * Find a message by the given identifier type.
     */
    public function find(int $id, ImapFetchIdentifier $identifier = ImapFetchIdentifier::Uid): ?Message
    {
        if (! $response = $this->uid($id, $identifier)->first()) {
            return null;
        }

        $uid = $response->tokenAt(3) // ListData
            ->tokenAt(1) // Atom
            ->value; // UID

        return $this->process(new MessageCollection([$uid]))->first();
    }

    /**
     * Get the UID for theb given identifier.
     */
    protected function uid(int $id, ImapFetchIdentifier $identifier = ImapFetchIdentifier::Uid): ResponseCollection
    {
        return $this->connection()->uid([$id], $identifier);
    }

    /**
     * Get the connection instance.
     */
    protected function connection(): ConnectionInterface
    {
        return $this->folder->mailbox()->connection();
    }
}
