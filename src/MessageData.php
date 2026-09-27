<?php

namespace DirectoryTree\ImapEngine;

use DirectoryTree\ImapEngine\MessageData\Attribute;
use DirectoryTree\ImapEngine\MessageData\Body;

class MessageData
{
    /**
     * Create a FLAGS message data item.
     */
    public static function flags(): Attribute
    {
        return Attribute::Flags;
    }

    /**
     * Create an RFC822.SIZE message data item.
     */
    public static function size(): Attribute
    {
        return Attribute::Size;
    }

    /**
     * Create a BODYSTRUCTURE message data item.
     */
    public static function bodyStructure(): Attribute
    {
        return Attribute::BodyStructure;
    }

    /**
     * Create a MODSEQ message data item.
     */
    public static function modSequence(): Attribute
    {
        return Attribute::ModSequence;
    }

    /**
     * Create a message header data item.
     */
    public static function headers(): Body
    {
        return Body::headers();
    }

    /**
     * Create a message text data item.
     */
    public static function text(): Body
    {
        return Body::text();
    }

    /**
     * Create a message body section data item.
     */
    public static function section(string $section): Body
    {
        return Body::section($section);
    }
}
