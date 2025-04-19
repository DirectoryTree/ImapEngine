<?php

namespace DirectoryTree\ImapEngine\Connection\Responses;

use DirectoryTree\ImapEngine\Connection\Responses\Data\ResponseCodeData;

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

    /**
     * Get the UID from a tagged move or copy response.
     */
    public static function getUidFromCopy(TaggedResponse $response): ?int
    {
        if (! $data = $response->tokenAt(2)) {
            return null;
        }

        if (! $data instanceof ResponseCodeData) {
            return null;
        }

        if (! $value = $data->tokenAt(3)?->value) {
            return null;
        }

        return (int) $value;
    }
}
