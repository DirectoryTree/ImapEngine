<?php

namespace DirectoryTree\ImapEngine\Connection;

use Carbon\Carbon;
use DateTimeInterface;
use DirectoryTree\ImapEngine\Support\Str;

class ImapQueryBuilder
{
    /**
     * The where conditions for the query.
     *
     * @var array<int, array<string, mixed>>
     */
    protected array $wheres = [];

    /**
     * The date format to use for date based queries.
     */
    protected string $dateFormat = 'd-M-Y';

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
     * Add a where condition.
     */
    public function where(callable|string $column, mixed $value = null): static
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
    public function orWhere(callable|string $column, mixed $value = null): static
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
    public function whereNot(string $column, mixed $value): static
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
     * Transform the instance into an IMAP-compatible query string.
     */
    public function toImap(): string
    {
        return $this->compileWheres($this->wheres);
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
    protected function addBasicCondition(string $boolean, string $column, mixed $value, bool $not = false): void
    {
        $value = $this->prepareWhereValue($value);

        $this->wheres[] = [
            'type' => 'basic',
            'not' => $not,
            'key' => $column,
            'value' => $value,
            'boolean' => $boolean,
        ];
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
     * Recursively compile the wheres array into an IMAP-compatible string.
     *
     * @param  array<int, array<string, mixed>>  $wheres
     */
    protected function compileWheres(array $wheres): string
    {
        if (empty($wheres)) {
            return '';
        }

        // Convert each "where" into a node for later merging.
        $exprNodes = array_map(fn ($where) => (
            $this->makeExpressionNode($where)
        ), $wheres);

        // Start with the first expression.
        $combined = array_shift($exprNodes)['expr'];

        // Merge the rest of the expressions.
        foreach ($exprNodes as $node) {
            $combined = $this->mergeExpressions(
                $combined, $node['expr'], $node['boolean']
            );
        }

        return trim($combined);
    }

    /**
     * Build a single expression node from a basic or nested where.
     *
     * @param  array<string, mixed>  $where
     * @return array<string, string>
     */
    protected function makeExpressionNode(array $where): array
    {
        return match ($where['type']) {
            'basic' => [
                'expr' => $this->compileBasic($where),
                'boolean' => $where['boolean'],
            ],

            'nested' => [
                'expr' => $where['query']->toImap(),
                'boolean' => $where['boolean'],
            ]
        };
    }

    /**
     * Merge the existing expression with the next expression, respecting the boolean operator.
     */
    protected function mergeExpressions(string $existing, string $next, string $boolean): string
    {
        return match ($boolean) {
            // AND is implicit – just append.
            'AND' => $existing.' '.$next,

            // IMAP's OR is binary; nest accordingly.
            'OR' => 'OR ('.$existing.') ('.$next.')',
        };
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
     * Attempt to parse a date string into a Carbon instance.
     */
    protected function parseDate(mixed $date): Carbon
    {
        if ($date instanceof Carbon) {
            return $date;
        }

        return Carbon::parse($date);
    }

    /**
     * Compile a basic where condition into an IMAP-compatible string.
     *
     * @param  array<string, mixed>  $where
     */
    protected function compileBasic(array $where): string
    {
        $part = strtoupper($where['key']).' "'.$where['value'].'"';

        if ($where['not']) {
            $part = 'NOT '.$part;
        }

        return $part;
    }
}
