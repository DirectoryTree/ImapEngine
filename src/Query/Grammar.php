<?php

namespace DirectoryTree\ImapEngine\Query;

class Grammar
{
    /**
     * Constructor.
     */
    public function __construct(
        protected array $filters = []
    ) {}

    /**
     * Get the raw IMAP search query.
     */
    public function compile(): string
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
}
