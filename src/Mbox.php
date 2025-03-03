<?php

namespace DirectoryTree\ImapEngine;

use DirectoryTree\ImapEngine\Exceptions\RuntimeException;
use Generator;

class Mbox
{
    /**
     * Constructor.
     */
    public function __construct(
        protected string $filepath
    ) {}

    /**
     * Get the messages from the mbox file.
     */
    public function messages(): Generator
    {
        if (! $handle = fopen($this->filepath, 'r')) {
            throw new RuntimeException('Failed to open mbox file: '.$this->filepath);
        }

        $buffer = '';

        while (($line = fgets($handle)) !== false) {
            if (str_starts_with($line, 'From ') && $buffer !== '') {
                yield new FileMessage($buffer);

                $buffer = '';
            }

            $buffer .= $line;
        }

        if ($buffer !== '') {
            yield new FileMessage($buffer);
        }

        fclose($handle);
    }
}
