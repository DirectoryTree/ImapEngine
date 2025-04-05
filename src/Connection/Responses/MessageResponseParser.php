<?php

namespace DirectoryTree\ImapEngine\Connection\Responses;

class MessageResponseParser
{
    /**
     * Get the flags from an untagged response.
     *
     * @return array<string, string[]>
     */
    public static function getFlags(UntaggedResponse $response): array
    {
        $data = $response->tokenAt(3);

        $uid = $data->lookup('UID')->value;
        $flags = $data->lookup('FLAGS')->values();

        return [$uid => $flags];
    }

    /**
     * Get the body header from an untagged response.
     *
     * @return array<string, string>
     */
    public static function getBodyHeader(UntaggedResponse $response): array
    {
        $data = $response->tokenAt(3);

        $uid = $data->lookup('UID')->value;
        $headers = $data->lookup('[HEADER]')->value;

        return [$uid => $headers];
    }

    /**
     * Get the body text from an untagged response.
     *
     * @return array<string, string>
     */
    public static function getBodyText(UntaggedResponse $response): array
    {
        $data = $response->tokenAt(3);

        $uid = $data->lookup('UID')->value;
        $contents = $data->lookup('[TEXT]')->value;

        return [$uid => $contents];
    }
}
