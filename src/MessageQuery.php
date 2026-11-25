<?php

namespace DirectoryTree\ImapEngine;

use BackedEnum;
use DirectoryTree\ImapEngine\Collections\MessageCollection;
use DirectoryTree\ImapEngine\Collections\ResponseCollection;
use DirectoryTree\ImapEngine\Connection\ConnectionInterface;
use DirectoryTree\ImapEngine\Connection\ImapQueryBuilder;
use DirectoryTree\ImapEngine\Connection\Responses\Data\ListData;
use DirectoryTree\ImapEngine\Connection\Responses\Data\ResponseCodeData;
use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
use DirectoryTree\ImapEngine\Connection\Tokens\Atom;
use DirectoryTree\ImapEngine\Connection\Tokens\Token;
use DirectoryTree\ImapEngine\Enums\ImapFetchIdentifier;
use DirectoryTree\ImapEngine\Enums\ImapFlag;
use DirectoryTree\ImapEngine\Exceptions\ImapCapabilityException;
use DirectoryTree\ImapEngine\Exceptions\ImapCommandException;
use DirectoryTree\ImapEngine\Exceptions\RuntimeException;
use DirectoryTree\ImapEngine\Pagination\Cursor;
use DirectoryTree\ImapEngine\Pagination\CursorPaginator;
use DirectoryTree\ImapEngine\Pagination\LengthAwarePaginator;
use DirectoryTree\ImapEngine\Support\Str;
use Generator;
use Illuminate\Support\Collection;
use Illuminate\Support\ItemNotFoundException;
use Illuminate\Support\LazyCollection;
use InvalidArgumentException;

/**
 * @mixin ImapQueryBuilder
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
        return $this->process($this->matchingUids());
    }

    /**
     * {@inheritDoc}
     */
    public function uids(): Collection
    {
        return $this->pageUids($this->matchingUids())
            ->values()
            ->map(fn (string|int $uid) => (int) $uid);
    }

    /**
     * Append a new message to the folder.
     */
    public function append(string $message, mixed $flags = null): int
    {
        $response = $this->connection()->append(
            $this->folder->path(), $message, (array) Str::enums($flags),
        );

        return (int) $response // TAG4 OK [APPENDUID <uidvalidity> <uid>] APPEND completed.
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
        $uids = $this->order($this->search());
        $total = $uids->count();

        foreach ($this->chunkUids($uids, $chunkSize, $startChunk) as $page => $chunk) {
            $messages = $this->load($chunk);

            $messages->total($total);

            if ($messages->isEmpty() || $callback($messages, $page) === false) {
                break;
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function lazy(int $chunkSize = 10): LazyCollection
    {
        return new LazyCollection(function () use ($chunkSize) {
            $this->folder->select();

            $uids = $this->order($this->sortKey ? $this->sort() : $this->search());

            foreach ($this->chunkUids($uids, $chunkSize) as $chunk) {
                // The caller may switch folders between batches.
                $this->folder->select();

                foreach ($this->load($chunk) as $message) {
                    yield $message;
                }
            }
        });
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
     * Paginate by stable UIDs, retaining the boundary before fetching content.
     *
     * @return CursorPaginator<MessageInterface>
     */
    public function cursorPaginate(int $perPage = 15, Cursor|string|null $cursor = null): CursorPaginator
    {
        if ($perPage < 1 || $this->sortKey !== null) {
            throw new InvalidArgumentException('Cursor pagination requires a positive page size and UID fetch order.');
        }

        if (is_string($cursor)) {
            $cursor = Cursor::fromEncoded($cursor);
        }

        $mailbox = $this->folder->mailbox()->fingerprint();
        $folder = $this->folder->path();
        $status = $this->folder->status();
        $uidValidity = (int) $status['UIDVALIDITY'];

        $cursor?->assertMatches($mailbox, $folder, $uidValidity, $this->fetchOrder);

        $maxUid = $cursor?->maxUid ?? (int) $status['UIDNEXT'] - 1;
        $from = $this->fetchOrder === 'asc' ? ($cursor?->uid ?? 0) + 1 : 1;
        $to = $this->fetchOrder === 'desc' ? ($cursor?->uid ?? $maxUid + 1) - 1 : $maxUid;

        if ($from > $to) {
            return new CursorPaginator(new MessageCollection);
        }

        // Keep the original query reusable when requesting subsequent pages.
        $query = clone $this;
        $query->query = clone $this->query;
        $query->uid($from, $to);

        $uids = $query->order($query->search());

        $selected = $uids->take($perPage);

        $next = $uids->count() > $perPage ? new Cursor(
            mailbox: $mailbox,
            folder: $folder,
            uid: (int) $selected->last(),
            maxUid: $maxUid,
            uidValidity: $uidValidity,
            direction: $this->fetchOrder,
        ) : null;

        // The cursor advances past the selected UIDs even if some disappear during FETCH.
        return new CursorPaginator($query->load($selected), $next);
    }

    /**
     * Find a message by the given identifier type or throw an exception.
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
     */
    public function find(int $id, ImapFetchIdentifier $identifier = ImapFetchIdentifier::Uid): ?MessageInterface
    {
        $response = $this->id($id, $identifier)->first();

        if (! $response instanceof UntaggedResponse) {
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
     * {@inheritDoc}
     */
    public function flag(BackedEnum|string $flag, string $operation, bool $expunge = false): int
    {
        $uids = $this->search()->all();

        if (empty($uids)) {
            return 0;
        }

        $this->connection()->store(
            (array) Str::enums($flag),
            $uids,
            mode: $operation
        );

        if ($expunge) {
            $this->folder->expunge();
        }

        return count($uids);
    }

    /**
     * {@inheritDoc}
     */
    public function markRead(): int
    {
        return $this->flag(ImapFlag::Seen, '+');
    }

    /**
     * {@inheritDoc}
     */
    public function markUnread(): int
    {
        return $this->flag(ImapFlag::Seen, '-');
    }

    /**
     * {@inheritDoc}
     */
    public function markFlagged(): int
    {
        return $this->flag(ImapFlag::Flagged, '+');
    }

    /**
     * {@inheritDoc}
     */
    public function unmarkFlagged(): int
    {
        return $this->flag(ImapFlag::Flagged, '-');
    }

    /**
     * {@inheritDoc}
     */
    public function delete(bool $expunge = false): int
    {
        return $this->flag(ImapFlag::Deleted, '+', $expunge);
    }

    /**
     * {@inheritDoc}
     */
    public function move(string $folder, bool $expunge = false): int
    {
        $uids = $this->search()->all();

        if (empty($uids)) {
            return 0;
        }

        $this->connection()->move($folder, $uids);

        if ($expunge) {
            $this->folder->expunge();
        }

        return count($uids);
    }

    /**
     * {@inheritDoc}
     */
    public function copy(string $folder): int
    {
        $uids = $this->search()->all();

        if (empty($uids)) {
            return 0;
        }

        $this->connection()->copy($folder, $uids);

        return count($uids);
    }

    /**
     * Load the current page and retain the full match count for pagination.
     */
    protected function process(Collection $uids): MessageCollection
    {
        $messages = $this->load($this->pageUids($uids));

        $messages->total($uids->count());

        return $messages;
    }

    /**
     * Get the UIDs for the current page in query order.
     */
    protected function pageUids(Collection $uids): Collection
    {
        return $this->order($uids)->forPage($this->page, $this->limit);
    }

    /**
     * Yield each page of UIDs in their existing order.
     *
     * @param  Collection<array-key, string|int>  $uids
     * @return Generator<int, Collection<array-key, string|int>>
     */
    protected function chunkUids(Collection $uids, int $chunkSize, int $startChunk = 1): Generator
    {
        $chunkSize = max($chunkSize, 1);
        $totalChunks = (int) ceil($uids->count() / $chunkSize);

        for ($page = max($startChunk, 1); $page <= $totalChunks; $page++) {
            yield $page => $uids->forPage($page, $chunkSize);
        }
    }

    /**
     * Load the selected messages in UID list order, skipping any that disappeared.
     */
    protected function load(Collection $uids): MessageCollection
    {
        $messages = MessageCollection::make();

        if ($uids->isEmpty()) {
            return $messages;
        }

        $responses = $this->fetch($uids->values());

        foreach ($uids as $uid) {
            if (! isset($responses[$uid])) {
                continue;
            }

            $response = $responses[$uid];

            $messages->push(
                $this->newMessage(
                    $uid,
                    $response['flags'] ?? [],
                    $response['head'] ?? '',
                    $response['body'] ?? '',
                    $response['size'] ?? null,
                    $response['bodystructure'] ?? null,
                )
            );
        }

        return $messages;
    }

    /**
     * Order message identifiers before pagination or chunking.
     */
    protected function order(Collection $uids): Collection
    {
        // Only apply client-side sorting when not using server-side sorting.
        // When sortKey is set, the IMAP SORT command already returns UIDs
        // in the correct order, so we should preserve that order.
        if ($this->sortKey) {
            return $uids;
        }

        return match ($this->fetchOrder) {
            'asc' => $uids->sort(SORT_NUMERIC),
            'desc' => $uids->sortDesc(SORT_NUMERIC),
        };
    }

    /**
     * Fetch a given id collection.
     */
    protected function fetch(Collection $uids): array
    {
        $fetch = [];

        if ($this->fetchFlags) {
            $fetch[] = 'FLAGS';
        }

        if ($this->fetchSize) {
            $fetch[] = 'RFC822.SIZE';
        }

        if ($this->fetchHeaders) {
            $fetch[] = $this->fetchAsUnread
                ? 'BODY.PEEK[HEADER]'
                : 'BODY[HEADER]';
        }

        if ($this->fetchBody) {
            $fetch[] = $this->fetchAsUnread
                ? 'BODY.PEEK[TEXT]'
                : 'BODY[TEXT]';
        }

        if ($this->fetchBodyStructure) {
            $fetch[] = 'BODYSTRUCTURE';
        }

        if (empty($fetch)) {
            return $uids->mapWithKeys(fn (string|int $uid) => [
                $uid => [
                    'size' => null,
                    'flags' => [],
                    'head' => '',
                    'body' => '',
                    'bodystructure' => null,
                ],
            ])->all();
        }

        return $this->connection()->fetch($fetch, $uids->all())->mapWithKeys(function (UntaggedResponse $response) {
            $data = $response->tokenAt(3);

            if (! $data instanceof ListData) {
                throw new RuntimeException(sprintf(
                    'Expected instance of %s at index 3 in FETCH response, got %s',
                    ListData::class,
                    get_debug_type($data)
                ));
            }

            // Index field names and BODY sections without stringifying message contents.
            $fields = [];

            foreach ($data->tokens() as $index => $token) {
                if ($token instanceof Atom || $token instanceof ResponseCodeData) {
                    $fields[(string) $token] ??= $data->tokenAt($index + 1);
                }
            }

            $uid = $fields['UID']->value;

            $size = $fields['RFC822.SIZE']->value ?? null;

            return [
                $uid => [
                    'size' => $size ? (int) $size : null,
                    'flags' => ($fields['FLAGS'] ?? null)?->values() ?? [],
                    'head' => $fields['[HEADER]']->value ?? '',
                    'body' => $fields['[TEXT]']->value ?? '',
                    'bodystructure' => $fields['BODYSTRUCTURE'] ?? null,
                ],
            ];
        })->all();
    }

    /**
     * Look up matching UIDs using the configured search or server-side sort.
     */
    protected function matchingUids(): Collection
    {
        return $this->sortKey ? $this->sort() : $this->search();
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
            fn (Token $token) => $token->value,
            $response->tokensAfter(2)
        ));
    }

    /**
     * Execute an IMAP UID SORT request using RFC 5256.
     */
    protected function sort(): Collection
    {
        if (! in_array('SORT', $this->folder->mailbox()->capabilities())) {
            throw new ImapCapabilityException(
                'Unable to sort messages. IMAP server does not support SORT capability.'
            );
        }

        if ($this->query->isEmpty()) {
            $this->query->all();
        }

        $response = $this->connection()->sort(
            $this->sortKey,
            $this->sortDirection,
            [$this->query->toImap()]
        );

        return new Collection(array_map(
            fn (Token $token) => $token->value,
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
    protected function newMessage(int $uid, array $flags, string $head, string $body, ?int $size = null, ?ListData $bodystructure = null): Message
    {
        return new Message($this->folder, $uid, $flags, $head, $body, $size, $bodystructure);
    }

    /**
     * Get the connection instance.
     */
    protected function connection(): ConnectionInterface
    {
        return $this->folder->mailbox()->connection();
    }
}
