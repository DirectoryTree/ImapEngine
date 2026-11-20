<?php

namespace DirectoryTree\ImapEngine\Connection;

use BackedEnum;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use DateTimeInterface;
use DirectoryTree\ImapEngine\Enums\ImapSearchKey;
use DirectoryTree\ImapEngine\Support\Str;
use ReflectionMethod;

class ImapQueryBuilder
{
    /**
     * The largest UID value allowed by IMAP.
     */
    protected const MAX_UID = '4294967295';

    /**
     * The where conditions for the query.
     */
    protected array $wheres = [];

    /**
     * The date format to use for date based queries.
     */
    protected string $dateFormat = 'd-M-Y';

    /**
     * Add a where "ALL" clause to the query.
     */
    public function all(): static
    {
        return $this->where(ImapSearchKey::All);
    }

    /**
     * Add a where "NEW" clause to the query.
     */
    public function new(): static
    {
        return $this->where(ImapSearchKey::New);
    }

    /**
     * Add a where "OLD" clause to the query.
     */
    public function old(): static
    {
        return $this->where(ImapSearchKey::Old);
    }

    /**
     * Add a where "SEEN" clause to the query.
     */
    public function seen(): static
    {
        return $this->where(ImapSearchKey::Seen);
    }

    /**
     * Add a where "DRAFT" clause to the query.
     */
    public function draft(): static
    {
        return $this->where(ImapSearchKey::Draft);
    }

    /**
     * Add a where "RECENT" clause to the query.
     */
    public function recent(): static
    {
        return $this->where(ImapSearchKey::Recent);
    }

    /**
     * Add a where "UNSEEN" clause to the query.
     */
    public function unseen(): static
    {
        return $this->where(ImapSearchKey::Unseen);
    }

    /**
     * Add a where "FLAGGED" clause to the query.
     */
    public function flagged(): static
    {
        return $this->where(ImapSearchKey::Flagged);
    }

    /**
     * Add a where "DELETED" clause to the query.
     */
    public function deleted(): static
    {
        return $this->where(ImapSearchKey::Deleted);
    }

    /**
     * Add a where "ANSWERED" clause to the query.
     */
    public function answered(): static
    {
        return $this->where(ImapSearchKey::Answered);
    }

    /**
     * Add a where "UNDELETED" clause to the query.
     */
    public function undeleted(): static
    {
        return $this->where(ImapSearchKey::Undeleted);
    }

    /**
     * Add a where "UNFLAGGED" clause to the query.
     */
    public function unflagged(): static
    {
        return $this->where(ImapSearchKey::Unflagged);
    }

    /**
     * Add a where "UNANSWERED" clause to the query.
     */
    public function unanswered(): static
    {
        return $this->where(ImapSearchKey::Unanswered);
    }

    /**
     * Add a where "FROM" clause to the query.
     */
    public function from(string $email): static
    {
        return $this->where(ImapSearchKey::From, $email);
    }

    /**
     * Add a where "TO" clause to the query.
     */
    public function to(string $value): static
    {
        return $this->where(ImapSearchKey::To, $value);
    }

    /**
     * Add a where "CC" clause to the query.
     */
    public function cc(string $value): static
    {
        return $this->where(ImapSearchKey::Cc, $value);
    }

    /**
     * Add a where "BCC" clause to the query.
     */
    public function bcc(string $value): static
    {
        return $this->where(ImapSearchKey::Bcc, $value);
    }

    /**
     * Add a where "BODY" clause to the query.
     */
    public function body(string $value): static
    {
        return $this->where(ImapSearchKey::Body, $value);
    }

    /**
     * Add a where "KEYWORD" clause to the query.
     */
    public function keyword(string $value): static
    {
        return $this->where(ImapSearchKey::Keyword, $value);
    }

    /**
     * Add a where "UNKEYWORD" clause to the query.
     */
    public function unkeyword(string $value): static
    {
        return $this->where(ImapSearchKey::Unkeyword, $value);
    }

    /**
     * Add a where "ON" clause to the query.
     */
    public function on(mixed $date): static
    {
        return $this->where(ImapSearchKey::On, new RawQueryValue(
            $this->parseDate($date)->format($this->dateFormat)
        ));
    }

    /**
     * Add a where "SINCE" clause to the query.
     */
    public function since(mixed $date): static
    {
        return $this->where(ImapSearchKey::Since, new RawQueryValue(
            $this->parseDate($date)->format($this->dateFormat)
        ));
    }

    /**
     * Add a where "BEFORE" clause to the query.
     */
    public function before(mixed $value): static
    {
        return $this->where(ImapSearchKey::Before, new RawQueryValue(
            $this->parseDate($value)->format($this->dateFormat)
        ));
    }

    /**
     * Add a where "SENTON" clause to the query.
     */
    public function sentOn(mixed $date): static
    {
        return $this->where(ImapSearchKey::SentOn, new RawQueryValue(
            $this->parseDate($date)->format($this->dateFormat)
        ));
    }

    /**
     * Add a where "SENTSINCE" clause to the query.
     */
    public function sentSince(mixed $date): static
    {
        return $this->where(ImapSearchKey::SentSince, new RawQueryValue(
            $this->parseDate($date)->format($this->dateFormat)
        ));
    }

    /**
     * Add a where "SENTBEFORE" clause to the query.
     */
    public function sentBefore(mixed $date): static
    {
        return $this->where(ImapSearchKey::SentBefore, new RawQueryValue(
            $this->parseDate($date)->format($this->dateFormat)
        ));
    }

    /**
     * Add a where "SUBJECT" clause to the query.
     */
    public function subject(string $value): static
    {
        return $this->where(ImapSearchKey::Subject, $value);
    }

    /**
     * Add a where "TEXT" clause to the query.
     */
    public function text(string $value): static
    {
        return $this->where(ImapSearchKey::Text, $value);
    }

    /**
     * Add a where "HEADER" clause to the query.
     */
    public function header(string $header, string $value): static
    {
        return $this->where(ImapSearchKey::Header->value." $header", $value);
    }

    /**
     * Add a where "HEADER Message-ID" clause to the query.
     */
    public function messageId(string $messageId): static
    {
        return $this->header('Message-ID', trim(trim($messageId), '<>'));
    }

    /**
     * Add a where "UID" clause to the query.
     */
    public function uid(int|string|array $from, int|float|null $to = null): static
    {
        if ($to === INF) {
            $to = self::MAX_UID;
        }

        return $this->where(ImapSearchKey::Uid, new RawQueryValue(Str::set($from, $to)));
    }

    /**
     * Add a where "LARGER" clause to the query.
     */
    public function larger(int $bytes): static
    {
        return $this->where(ImapSearchKey::Larger, new RawQueryValue($bytes));
    }

    /**
     * Add a where "SMALLER" clause to the query.
     */
    public function smaller(int $bytes): static
    {
        return $this->where(ImapSearchKey::Smaller, new RawQueryValue($bytes));
    }

    /**
     * Add a "where" condition.
     */
    public function where(mixed $column, mixed $value = null): static
    {
        if (is_callable($column)) {
            $this->addNestedCondition('AND', $column);
        } else {
            $this->addBasicCondition('AND', $column, $value);
        }

        return $this;
    }

    /**
     * Add an "or where" condition.
     */
    public function orWhere(mixed $column, mixed $value = null): static
    {
        if (is_callable($column)) {
            $this->addNestedCondition('OR', $column);
        } else {
            $this->addBasicCondition('OR', $column, $value);
        }

        return $this;
    }

    /**
     * Add a "where not" condition.
     */
    public function whereNot(mixed $column, mixed $value = null): static
    {
        $this->addBasicCondition('AND', $column, $value, true);

        return $this;
    }

    /**
     * Determine if the query has any where conditions.
     */
    public function isEmpty(): bool
    {
        return empty($this->wheres);
    }

    /**
     * Compile the criteria as a single query string.
     */
    public function toImap(): string
    {
        // A subclass calling parent::toImap() must not re-enter its own override.
        $tokens = $this->overridesToImap()
            ? $this->grammar()->compile($this->wheres)
            : $this->toImapTokens();

        return ltrim((string) new ImapCommand('', '', $tokens), ' ');
    }

    /**
     * Compile search criteria for transmission, preserving literal arguments.
     *
     * @return list<string|ImapCommandArgument>
     */
    public function toImapTokens(): array
    {
        // Older builders may customize the outgoing criteria through toImap().
        if ($this->overridesToImap()) {
            return [$this->toImap()];
        }

        return $this->grammar()->compile($this->wheres);
    }

    /**
     * Determine whether the builder supplies its own IMAP query string.
     */
    protected function overridesToImap(): bool
    {
        return static::class !== self::class && (new ReflectionMethod($this, 'toImap'))->getDeclaringClass()->getName() !== self::class;
    }

    /**
     * Get the conditions recorded by the builder.
     */
    public function getWheres(): array
    {
        return $this->wheres;
    }

    /**
     * Get the grammar used to compile search conditions.
     */
    protected function grammar(): ImapSearchGrammar
    {
        return new ImapSearchGrammar;
    }

    /**
     * Create a new query instance (like Eloquent's newQuery).
     */
    protected function newQuery(): static
    {
        return new static;
    }

    /**
     * Add a basic condition to the query.
     */
    protected function addBasicCondition(string $boolean, mixed $column, mixed $value, bool $not = false): void
    {
        $this->wheres[] = [
            'type' => 'basic',
            'not' => $not,
            'key' => Str::enum($column),
            'value' => $this->normalizeWhereValue($value),
            'boolean' => $boolean,
        ];
    }

    /**
     * Normalize the where value for compilation.
     */
    protected function normalizeWhereValue(mixed $value): RawQueryValue|string|null
    {
        if (is_null($value)) {
            return null;
        }

        if ($value instanceof RawQueryValue) {
            return $value;
        }

        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        if ($value instanceof DateTimeInterface) {
            $value = Carbon::instance($value);
        }

        if ($value instanceof CarbonInterface) {
            $value = $value->format($this->dateFormat);
        }

        return $value;
    }

    /**
     * Add a nested condition group to the query.
     */
    protected function addNestedCondition(string $boolean, callable $callback): void
    {
        $nested = $this->newQuery();

        $callback($nested);

        $this->wheres[] = [
            'type' => 'nested',
            'query' => $nested,
            'boolean' => $boolean,
        ];
    }

    /**
     * Attempt to parse a date string into a Carbon instance.
     */
    protected function parseDate(mixed $date): CarbonInterface
    {
        if ($date instanceof CarbonInterface) {
            return $date;
        }

        return Carbon::parse($date);
    }
}
