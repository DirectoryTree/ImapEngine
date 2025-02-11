<?php

namespace DirectoryTree\ImapEngine;

use Stringable;
use Symfony\Component\Mime\Email;

class Draft implements Stringable
{
    /**
     * The underlying Symfony Email instance.
     */
    protected Email $message;

    /**
     * Constructor.
     */
    public function __construct(
        protected ?string $from = null,
        protected array $to = [],
        protected ?string $subject = null,
        protected ?string $text = null,
        protected ?string $html = null,
        protected array $cc = [],
        protected array $bcc = [],
        protected array $attachments = [],
    ) {
        $this->message = new Email;

        if ($this->from) {
            $this->message->from($this->from);
        }

        if (! empty($this->to)) {
            $this->message->to(...$this->to);
        }

        if ($this->subject) {
            $this->message->subject($this->subject);
        }

        if (! empty($this->cc)) {
            $this->message->cc(...$this->cc);
        }

        if (! empty($this->bcc)) {
            $this->message->bcc(...$this->bcc);
        }

        if ($this->text) {
            $this->message->text($this->text);
        }

        if ($this->html) {
            $this->message->html($this->html);
        }

        foreach ($this->attachments as $path) {
            $this->message->attachFromPath($path);
        }
    }

    /**
     * Get the underlying Symfony Email instance.
     */
    public function email(): Email
    {
        return $this->message;
    }

    /**
     * Get the email as a string.
     */
    public function __toString(): string
    {
        return $this->message->toString();
    }
}
