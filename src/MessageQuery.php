<?php

namespace DirectoryTree\ImapEngine;

use Carbon\Carbon;
use Closure;
use DateTimeInterface;
use DirectoryTree\ImapEngine\Collections\MessageCollection;
use DirectoryTree\ImapEngine\Connection\ConnectionInterface;
use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
use DirectoryTree\ImapEngine\Connection\Tokens\Atom;
use DirectoryTree\ImapEngine\Support\Str;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Traits\Conditionable;

class MessageQuery
{
    use Conditionable;

    /**
     * The folder to search in.
     */
    protected Folder $folder;

    /**
     * The current page.
     */
    protected int $page = 1;

    /**
     * The fetch limit.
     */
    protected ?int $limit = null;

    /**
     * The added search conditions.
     */
    protected array $conditions = [];

    /**
     * The date format to use for date based queries.
     */
    protected string $dateFormat = 'd-M-Y';

    /**
     * Whether to fetch the message body.
     */
    protected bool $fetchBody = false;

    /**
     * Whether to fetch the message flags.
     */
    protected bool $fetchFlags = true;

    /**
     * Whether to fetch the message headers.
     */
    protected bool $fetchHeaders = true;

    /**
     * The fetch order.
     */
    protected string $fetchOrder = 'desc';

    /**
     * Whether to leave messages fetched as unread by default.
     */
    protected bool $fetchAsUnread = true;

    /**
     * Constructor.
     */
    public function __construct(Folder $folder)
    {
        $this->folder = $folder;
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
     * Set the fetch option flag.
     */
    public function setFetchOptions(int $fetchOptions): static
    {
        $this->fetchOptions = $fetchOptions;

        return $this;
    }

    /**
     * Get the fetch option flag.
     */
    public function getFetchOptions(): ?int
    {
        return $this->fetchOptions;
    }

    /**
     * Determine if the body of messages is being fetched.
     */
    public function isFetchingBody(): bool
    {
        return $this->fetchBody;
    }

    /**
     * Fetch the body of messages.
     */
    public function withBody(): static
    {
        return $this->setFetchBody(true);
    }

    /**
     * Don't fetch the body of messages.
     */
    public function withoutBody(): static
    {
        return $this->setFetchBody(false);
    }

    /**
     * Set the fetch body flag.
     */
    public function setFetchBody(bool $fetchBody): static
    {
        $this->fetchBody = $fetchBody;

        return $this;
    }

    /**
     * Get the fetch body flag.
     */
    public function getFetchFlags(): bool
    {
        return $this->fetchFlags;
    }

    /**
     * Set the fetch flag.
     */
    public function setFetchFlags(bool $fetchFlags): static
    {
        $this->fetchFlags = $fetchFlags;

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
     * Add a where clause to the query.
     */
    public function where(mixed $criteria, mixed $value = null): static
    {
        if (is_array($criteria)) {
            foreach ($criteria as $key => $value) {
                is_numeric($key)
                    ? $this->where($value)
                    : $this->where($key, $value);
            }
        } else {
            $this->addCondition($criteria, $this->prepareWhereValue($value));
        }

        return $this;
    }

    /**
     * Push a given search criteria and value pair to the search query.
     */
    protected function addCondition(string $criteria, mixed $value): void
    {
        if (empty($value)) {
            $this->conditions[] = [$criteria];
        } else {
            $this->conditions[] = [$criteria, $value];
        }
    }

    /**
     * Prepare the where value, escaping it as needed.
     */
    protected function prepareWhereValue(mixed $value): ?string
    {
        if (is_null($value)) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            $value = Carbon::instance($value);
        }

        if ($value instanceof Carbon) {
            $value = $value->format($this->dateFormat);
        }

        return Str::escape($value);
    }

    /**
     * Add an "OR" clause to the query.
     */
    public function orWhere(?Closure $closure = null): static
    {
        $this->addCondition('OR', null);

        if ($closure) {
            $closure($this);
        }

        return $this;
    }

    /**
     * Add an "AND" clause to the query.
     */
    public function andWhere(?Closure $closure = null): static
    {
        $this->addCondition('AND', null);

        if ($closure) {
            $closure($this);
        }

        return $this;
    }

    /**
     * Add a where all clause to the query.
     */
    public function all(): static
    {
        return $this->where('ALL');
    }

    /**
     * Add a where answered clause to the query.
     */
    public function answered(): static
    {
        return $this->where('ANSWERED');
    }

    /**
     * Add a where bcc clause to the query.
     */
    public function bcc(string $value): static
    {
        return $this->where('BCC', $value);
    }

    /**
     * Add a where body clause to the query.
     */
    public function body(string $value): static
    {
        return $this->where('BODY', $value);
    }

    /**
     * Add a where cc clause to the query.
     */
    public function cc(string $value): static
    {
        return $this->where('CC', $value);
    }

    /**
     * Add a where deleted clause to the query.
     */
    public function deleted(): static
    {
        return $this->where('DELETED');
    }

    /**
     * Add a where flagged clause to the query.
     */
    public function flagged(string $value): static
    {
        return $this->where('FLAGGED', $value);
    }

    /**
     * Add a where from clause to the query.
     */
    public function from(string $email): static
    {
        return $this->where('FROM', $email);
    }

    /**
     * Add a where keyword clause to the query.
     */
    public function keyword(string $value): static
    {
        return $this->where('KEYWORD', $value);
    }

    /**
     * Add a where new clause to the query.
     */
    public function new(): static
    {
        return $this->where('NEW');
    }

    /**
     * Add a where not clause to the query.
     */
    public function not(): static
    {
        return $this->where('NOT');
    }

    /**
     * Add a where old clause to the query.
     */
    public function old(): static
    {
        return $this->where('OLD');
    }

    /**
     * Add a where on clause to the query.
     */
    public function on(mixed $date): static
    {
        return $this->where('ON', $this->parseDate($date));
    }

    /**
     * Add a where since clause to the query.
     */
    public function since(mixed $date): static
    {
        return $this->where('SINCE', $this->parseDate($date));
    }

    /**
     * Add a where before clause to the query.
     */
    public function before(mixed $value): static
    {
        return $this->where('BEFORE', $this->parseDate($value));
    }

    /**
     * Add a where recent clause to the query.
     */
    public function recent(): static
    {
        return $this->where('RECENT');
    }

    /**
     * Add a where seen clause to the query.
     */
    public function seen(): static
    {
        return $this->where('SEEN');
    }

    /**
     * Add a where subject clause to the query.
     */
    public function subject(string $value): static
    {
        return $this->where('SUBJECT', $value);
    }

    /**
     * Add a where text clause to the query.
     */
    public function text(string $value): static
    {
        return $this->where('TEXT', $value);
    }

    /**
     * Add a where to clause to the query.
     */
    public function to(string $value): static
    {
        return $this->where('TO', $value);
    }

    /**
     * Add a where unkeyword clause to the query.
     */
    public function unkeyword(string $value): static
    {
        return $this->where('UNKEYWORD', $value);
    }

    /**
     * Add a where undeleted clause to the query.
     */
    public function unanswered(): static
    {
        return $this->where('UNANSWERED');
    }

    /**
     * Add a where undeleted clause to the query.
     */
    public function undeleted(): static
    {
        return $this->where('UNDELETED');
    }

    /**
     * Add a where unflagged clause to the query.
     */
    public function unflagged(): static
    {
        return $this->where('UNFLAGGED');
    }

    /**
     * Add a where unseen clause to the query.
     */
    public function unseen(): static
    {
        return $this->where('UNSEEN');
    }

    /**
     * Add a where header clause to the query.
     */
    public function header(string $header, string $value): static
    {
        return $this->where("HEADER $header", $value);
    }

    /**
     * Add a where message id clause to the query.
     */
    public function messageId(string $messageId): static
    {
        return $this->header('Message-ID', $messageId);
    }

    /**
     * Add a where in reply to clause to the query.
     */
    public function inReplyTo(string $messageId): static
    {
        return $this->header('In-Reply-To', $messageId);
    }

    /**
     * Add a where language clause to the query.
     */
    public function language(string $countryCode): static
    {
        return $this->where("Content-Language $countryCode");
    }

    /**
     * Add a where UID clause to the query.
     */
    public function uid(int|string|array $uid): static
    {
        return $this->where('UID', implode(',', (array) $uid));
    }

    /**
     * Check if a given date is a valid carbon object and if not try to convert it.
     */
    protected function parseDate(mixed $date): Carbon
    {
        if ($date instanceof Carbon) {
            return $date;
        }

        return Carbon::parse($date);
    }

    /**
     * Get the raw IMAP search query.
     */
    public function getQuery(): string
    {
        $query = '';

        foreach ($this->conditions as $statement) {
            if (count($statement) === 1) {
                $query .= $statement[0].' ';

                continue;
            }

            if (is_null($statement[1])) {
                $query .= $statement[0].' ';

                continue;
            }

            if (is_numeric($statement[1])) {
                $query .= $statement[0].' '.$statement[1].' ';

                continue;
            }

            $query .= $statement[0].' "'.$statement[1].'" ';
        }

        return trim($query);
    }

    /**
     * Execute an IMAP search request.
     */
    protected function search(): Collection
    {
        if (empty($this->conditions)) {
            $this->all();
        }

        $response = $this->connection()->search([
            $this->getQuery(),
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
                $data = $response->tokenAt(3); // ListData

                $uid = $data->tokenAt(1)->value; // UID
                $flags = $data->tokenAt(3); // ListData (Flags)

                return [$uid => $flags->values()];
            }) : new Collection;

        $headers = $this->fetchHeaders ? $this->connection()
            ->headers($uids, $this->fetchAsUnread)
            ->mapWithKeys(function (UntaggedResponse $response) {
                $data = $response->tokenAt(3); // ListData

                $uid = $data->tokenAt(1)->value; // UID
                $headers = $data->tokenAt(4)->value; // Headers

                return [$uid => $headers];
            }) : new Collection;

        $contents = $this->fetchBody ? $this->connection()
            ->contents($uids, $this->fetchAsUnread)
            ->mapWithKeys(function (UntaggedResponse $response) {
                $data = $response->tokenAt(3); // ListData

                $uid = $data->tokenAt(1)->value; // UID
                $contents = $data->tokenAt(4)->value; // Contents

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
        return $this->get()->first();
    }

    /**
     * Get the messages matching the current query.
     */
    public function get(): MessageCollection
    {
        return $this->process($this->search());
    }

    /**
     * Fetch the current query as chunked requests.
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
     * Find a message by the given identifier type.
     */
    public function find(int $id, int $identifier = Imap::SEQUENCE_TYPE_UID): Message
    {
        // If the sequence is not UID, we'll need to fetch the UID first.
        $uid = match ($identifier) {
            Imap::SEQUENCE_TYPE_UID => $id,
            Imap::SEQUENCE_TYPE_MSG_NUMBER => $this->connection()->uids([10]) // ResponseCollection
                ->firstOrFail() // Untagged response
                ->tokenAt(3) // ListData
                ->tokenAt(1) // Atom
                ->value // UID
        };

        return $this->process(new MessageCollection([$uid]))->first();
    }

    /**
     * Get the connection instance.
     */
    protected function connection(): ConnectionInterface
    {
        return $this->folder->mailbox()->connection();
    }
}
