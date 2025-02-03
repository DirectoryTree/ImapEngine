<?php

namespace DirectoryTree\ImapEngine;

use Carbon\Carbon;
use Closure;
use DirectoryTree\ImapEngine\Collections\MessageCollection;
use DirectoryTree\ImapEngine\Exceptions\ConnectionFailedException;
use DirectoryTree\ImapEngine\Exceptions\GetMessagesFailedException;
use DirectoryTree\ImapEngine\Exceptions\InvalidWhereQueryCriteriaException;
use DirectoryTree\ImapEngine\Exceptions\MessageSearchValidationException;
use DirectoryTree\ImapEngine\Exceptions\RuntimeException;
use Exception;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
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
     * The added search filters.
     */
    protected array $filters = [];

    /**
     * The IMAP extensions that should be used.
     *
     * @var string[]
     */
    protected array $extensions;

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
    protected bool $fetchBody = true;

    /**
     * Whether to fetch the message flags.
     */
    protected bool $fetchFlags = true;

    /**
     * The fetch options.
     *
     * Leave messages unread by default.
     */
    protected int $fetchOptions = Imap::FT_PEEK;

    /**
     * The fetch order.
     */
    protected string $fetchOrder = 'desc';

    /**
     * @var mixed|int
     */
    protected mixed $sequence = Imap::NIL;

    protected array $options = [];

    /**
     * The errors that occurred while fetching messages.
     */
    protected array $errors = [];

    /**
     * The date format to use for date based queries.
     */
    protected string $dateFormat = 'd-M-Y';

    /**
     * The available search criteria.
     */
    protected array $criteria = [
        'OR', 'AND',
        'ALL', 'ANSWERED', 'BCC', 'BEFORE', 'BODY', 'CC', 'DELETED', 'FLAGGED', 'FROM', 'KEYWORD',
        'NEW', 'NOT', 'OLD', 'ON', 'RECENT', 'SEEN', 'SINCE', 'SUBJECT', 'TEXT', 'TO',
        'UNANSWERED', 'UNDELETED', 'UNFLAGGED', 'UNKEYWORD', 'UNSEEN', 'UID',
    ];

    /**
     * Constructor.
     */
    public function __construct(Folder $folder, array $options = [], array $extensions = [])
    {
        $this->folder = $folder;

        $this->sequence = Arr::get($options, 'sequence', Imap::ST_MSGN);

        $this->setExtensions($extensions);
    }

    /**
     * Don't mark messages as read when fetching.
     */
    public function leaveUnread(): static
    {
        $this->setFetchOptions(Imap::FT_PEEK);

        return $this;
    }

    /**
     * Mark all messages as read when fetching.
     */
    public function markAsRead(): static
    {
        $this->setFetchOptions(Imap::FT_UID);

        return $this;
    }

    /**
     * Set the sequence type.
     */
    public function setSequence(int $sequence): static
    {
        $this->sequence = $sequence;

        return $this;
    }

    /**
     * Get the sequence type.
     */
    public function getSequence(): int|string
    {
        return $this->sequence;
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
     * Get all applied extensions.
     *
     * @return string[]
     */
    public function getExtensions(): array
    {
        return $this->extensions;
    }

    /**
     * Set all extensions that should be used.
     *
     * @param  string[]  $extensions
     */
    public function setExtensions(array $extensions): static
    {
        $this->extensions = $extensions;

        if (count($this->extensions) > 0) {
            if (in_array('UID', $this->extensions) === false) {
                $this->extensions[] = 'UID';
            }
        }

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
     * Set the fetch option flag.
     */
    public function fetchOptions(int $fetchOptions): static
    {
        return $this->setFetchOptions($fetchOptions);
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
            $this->addFilter($criteria, $this->prepareWhereValue($value));
        }

        return $this;
    }

    /**
     * Push a given search criteria and value pair to the search query.
     */
    protected function addFilter(string $criteria, mixed $value): void
    {
        $criteria = $this->getValidCriteria($criteria);

        if (empty($value)) {
            $this->filters[] = [$criteria];
        } else {
            $this->filters[] = [$criteria, $value];
        }
    }

    /**
     * Prepare the where value.
     */
    protected function prepareWhereValue(mixed $value): string
    {
        if ($value instanceof Carbon) {
            $value = $value->format($this->dateFormat);
        }

        return (string) $value;
    }

    /**
     * Validate the given criteria.
     */
    protected function getValidCriteria(string $criteria): string
    {
        $command = strtoupper($criteria);

        if (str_starts_with($command, 'CUSTOM ')) {
            return substr($criteria, 7);
        }

        if (in_array($command, $this->criteria) === false) {
            throw new InvalidWhereQueryCriteriaException("Invalid imap search criteria: $command");
        }

        return $criteria;
    }

    /**
     * Add an "OR" clause to the query.
     */
    public function orWhere(?Closure $closure = null): static
    {
        $this->addFilter('OR', null);

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
        $this->addFilter('AND', null);

        if ($closure) {
            $closure($this);
        }

        return $this;
    }

    /**
     * Add a where all clause to the query.
     */
    public function whereAll(): static
    {
        return $this->where('ALL');
    }

    /**
     * Add a where answered clause to the query.
     */
    public function whereAnswered(): static
    {
        return $this->where('ANSWERED');
    }

    /**
     * Add a where bcc clause to the query.
     */
    public function whereBcc(string $value): static
    {
        return $this->where('BCC', $value);
    }

    /**
     * Add a where before clause to the query.
     */
    public function whereBefore(mixed $value): static
    {
        $date = $this->parseDate($value);

        return $this->where('BEFORE', $date);
    }

    /**
     * Add a where body clause to the query.
     */
    public function whereBody(string $value): static
    {
        return $this->where('BODY', $value);
    }

    /**
     * Add a where cc clause to the query.
     */
    public function whereCc(string $value): static
    {
        return $this->where('CC', $value);
    }

    /**
     * Add a where deleted clause to the query.
     */
    public function whereDeleted(): static
    {
        return $this->where('DELETED');
    }

    /**
     * Add a where flagged clause to the query.
     */
    public function whereFlagged(string $value): static
    {
        return $this->where('FLAGGED', $value);
    }

    /**
     * Add a where from clause to the query.
     */
    public function whereFrom(string $value): static
    {
        return $this->where('FROM', $value);
    }

    /**
     * Add a where keyword clause to the query.
     */
    public function whereKeyword(string $value): static
    {
        return $this->where('KEYWORD', $value);
    }

    /**
     * Add a where new clause to the query.
     */
    public function whereNew(): static
    {
        return $this->where('NEW');
    }

    /**
     * Add a where not clause to the query.
     */
    public function whereNot(): static
    {
        return $this->where('NOT');
    }

    /**
     * Add a where old clause to the query.
     */
    public function whereOld(): static
    {
        return $this->where('OLD');
    }

    /**
     * Add a where on clause to the query.
     */
    public function whereOn(mixed $value): static
    {
        $date = $this->parseDate($value);

        return $this->where('ON', $date);
    }

    /**
     * Add a where recent clause to the query.
     */
    public function whereRecent(): static
    {
        return $this->where('RECENT');
    }

    /**
     * Add a where seen clause to the query.
     */
    public function whereSeen(): static
    {
        return $this->where('SEEN');
    }

    /**
     * Add a where since clause to the query.
     */
    public function whereSince(mixed $value): static
    {
        $date = $this->parseDate($value);

        return $this->where('SINCE', $date);
    }

    /**
     * Add a where subject clause to the query.
     */
    public function whereSubject(string $value): static
    {
        return $this->where('SUBJECT', $value);
    }

    /**
     * Add a where text clause to the query.
     */
    public function whereText(string $value): static
    {
        return $this->where('TEXT', $value);
    }

    /**
     * Add a where to clause to the query.
     */
    public function whereTo(string $value): static
    {
        return $this->where('TO', $value);
    }

    /**
     * Add a where unkeyword clause to the query.
     */
    public function whereUnkeyword(string $value): static
    {
        return $this->where('UNKEYWORD', $value);
    }

    /**
     * Add a where undeleted clause to the query.
     */
    public function whereUnanswered(): static
    {
        return $this->where('UNANSWERED');
    }

    /**
     * Add a where undeleted clause to the query.
     */
    public function whereUndeleted(): static
    {
        return $this->where('UNDELETED');
    }

    /**
     * Add a where unflagged clause to the query.
     */
    public function whereUnflagged(): static
    {
        return $this->where('UNFLAGGED');
    }

    /**
     * Add a where unseen clause to the query.
     */
    public function whereUnseen(): static
    {
        return $this->where('UNSEEN');
    }

    /**
     * Add a where is not spam clause to the query.
     */
    public function whereNoXSpam(): static
    {
        return $this->where('CUSTOM X-Spam-Flag NO');
    }

    /**
     * Add a where is spam clause to the query.
     */
    public function whereIsXSpam(): static
    {
        return $this->where('CUSTOM X-Spam-Flag YES');
    }

    /**
     * Add a where header clause to the query.
     */
    public function whereHeader($header, $value): static
    {
        return $this->where("CUSTOM HEADER $header $value");
    }

    /**
     * Add a where message id clause to the query.
     */
    public function whereMessageId($messageId): static
    {
        return $this->whereHeader('Message-ID', $messageId);
    }

    /**
     * Add a where in reply to clause to the query.
     */
    public function whereInReplyTo($messageId): static
    {
        return $this->whereHeader('In-Reply-To', $messageId);
    }

    /**
     * Add a where language clause to the query.
     */
    public function whereLanguage($countryCode): static
    {
        return $this->where("Content-Language $countryCode");
    }

    /**
     * Add a where UID clause to the query.
     */
    public function whereUid(int|string $uid): static
    {
        return $this->where('UID', $uid);
    }

    /**
     * Get messages by their UIDs.
     *
     * @param  array<int, int>  $uids
     */
    public function whereUidIn(array $uids): static
    {
        return $this->where('UID', implode(',', $uids));
    }

    /**
     * Get all available search criteria.
     *
     * @return array|string[]
     */
    public function getCriteria(): array
    {
        return $this->criteria;
    }

    /**
     * Check if a given date is a valid carbon object and if not try to convert it.
     */
    protected function parseDate(mixed $date): Carbon
    {
        if ($date instanceof Carbon) {
            return $date;
        }

        try {
            $date = Carbon::parse($date);
        } catch (Exception) {
            throw new MessageSearchValidationException;
        }

        return $date;
    }

    /**
     * Get the raw IMAP search query.
     */
    public function getQuery(): string
    {
        $query = '';

        foreach ($this->filters as $statement) {
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
     * Execute an imap search request.
     */
    protected function search(): Collection
    {
        try {
            $messages = $this->folder->mailbox()
                ->connection()
                ->search([$this->getQuery()], $this->sequence)
                ->getValidatedData();

            return new Collection($messages);
        } catch (RuntimeException|ConnectionFailedException $e) {
            throw new GetMessagesFailedException('failed to fetch messages', 0, $e);
        }
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

        $flags = $this->folder->mailbox()
            ->connection()
            ->flags($uids, $this->sequence)
            ->getValidatedData();

        $headers = $this->folder->mailbox()
            ->connection()
            ->headers($uids, 'RFC822', $this->sequence)
            ->getValidatedData();

        if (! empty($extensions = $this->getExtensions())) {
            $extensions = $this->folder->mailbox()
                ->connection()
                ->fetch($this->getExtensions(), $uids, null, $this->sequence)
                ->getValidatedData();
        }

        $contents = [];

        if ($this->isFetchingBody()) {
            $contents = $this->folder->mailbox()
                ->connection()
                ->content($uids, 'RFC822', $this->sequence)
                ->getValidatedData();
        }

        return [
            'uids' => $uids,
            'flags' => $flags,
            'headers' => $headers,
            'contents' => $contents,
            'extensions' => $extensions,
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
     * Get the message key for a given message.
     */
    protected function getMessageKey(string $messageKey, int $msglist, Message $message): string
    {
        $key = match ($messageKey) {
            'number' => $message->getMessageNo(),
            'list' => $msglist,
            'uid' => $message->getUid(),
            default => $message->getMessageId(),
        };

        return (string) $key;
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
    protected function populate(Collection $availableMessages): MessageCollection
    {
        $messages = MessageCollection::make();

        $messages->total($availableMessages->count());

        $messageKey = $this->folder->mailbox()->config('options.message_key');

        $rawMessages = $this->fetch($availableMessages);

        $msglist = 0;

        foreach ($rawMessages['headers'] as $uid => $headers) {
            $flags = $rawMessages['flags'][$uid] ?? [];
            $contents = $rawMessages['contents'][$uid] ?? '';
            $extensions = $rawMessages['extensions'][$uid] ?? [];

            $message = $this->newMessage($uid, $flags, $headers, $contents);

            //            dd($extensions);
            //
            //            foreach ($extensions as $key => $extension) {
            //                $message->getHeader()->set($key, $extension);
            //            }

            $key = $this->getMessageKey($messageKey, $msglist, $message);

            $messages->put("$key", $message);

            $msglist++;
        }

        return $messages;
    }

    /**
     * Fetch the current query and return all found messages.
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
        $skippedMessagesCount = $chunkSize * ($startChunk - 1);

        $availableMessages = $this->search();
        $availableMessagesCount = max($availableMessages->count() - $skippedMessagesCount, 0);

        if (! $availableMessagesCount) {
            return;
        }

        $previousLimit = $this->limit;
        $previousPage = $this->page;

        $this->limit = $chunkSize;
        $this->page = $startChunk;

        $handledMessagesCount = 0;

        do {
            $messages = $this->populate($availableMessages);

            $handledMessagesCount += $messages->count();

            $callback($messages, $this->page);

            $this->page++;
        } while ($handledMessagesCount < $availableMessagesCount);

        $this->limit = $previousLimit;
        $this->page = $previousPage;
    }

    /**
     * Paginate the current query.
     *
     * @param  int  $perPage  Results you which to receive per page
     * @param  null  $page  The current page you are on (e.g. 0, 1, 2, ...) use `null` to enable auto mode
     * @param  string  $pageName  The page name / uri parameter used for the generated links and the auto mode
     */
    public function paginate(int $perPage = 5, $page = null, string $pageName = 'imap_page'): LengthAwarePaginator
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
     * Get a new Message instance.
     *
     * @param  null  $msglist
     * @param  null  $sequence
     */
    public function getMessage(int $uid, $msglist = null, $sequence = null): Message
    {
        return new Message(
            $uid,
            $msglist,
            $this->getMailbox,
            $this->getFetchOptions(),
            $this->isFetchingBody(),
            $this->getFetchFlags(),
            $sequence ?: $this->sequence
        );
    }

    /**
     * Get a message by its message number.
     *
     * @param  null  $msglist
     */
    public function getMessageByMsgn($msgn, $msglist = null): Message
    {
        return $this->getMessage($msgn, $msglist, Imap::ST_MSGN);
    }

    /**
     * Get a message by its uid.
     */
    public function getMessageByUid($uid): Message
    {
        return $this->getMessage($uid, null, Imap::ST_UID);
    }
}
