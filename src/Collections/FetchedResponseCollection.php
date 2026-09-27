<?php

namespace DirectoryTree\ImapEngine\Collections;

use DirectoryTree\ImapEngine\Enums\ImapIdentifier;
use DirectoryTree\ImapEngine\FetchedMessageData;
use DirectoryTree\ImapEngine\FetchedResponse;
use DirectoryTree\ImapEngine\Support\MessageSetMatcher;
use Illuminate\Support\Collection;

/**
 * @extends Collection<int, FetchedResponse>
 */
class FetchedResponseCollection extends Collection
{
    /**
     * Filter the responses to those belonging to the given message set.
     */
    public function forMessageSet(string $set, ImapIdentifier $identifier): static
    {
        $matcher = new MessageSetMatcher($set);

        return $this->filter(function (FetchedResponse $fetch) use ($matcher, $identifier) {
            if ($identifier === ImapIdentifier::Uid && ! $fetch->data()->has('UID')) {
                return false;
            }

            $number = $identifier === ImapIdentifier::Uid
                ? $fetch->data()->uid()
                : $fetch->sequenceNumber();

            return $matcher->contains($number);
        });
    }

    /**
     * Filter the responses to those containing all requested data items.
     */
    public function withItems(array $items): static
    {
        return $this->filter(function (FetchedResponse $fetch) use ($items) {
            foreach ($items as $item) {
                $key = str_replace(['BODY.PEEK[', 'BINARY.PEEK['], ['BODY[', 'BINARY['], strtoupper($item));
                $key = preg_replace('/<(\d+)\.\d+>$/', '<$1>', $key);

                if (! $fetch->data()->has($key)) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * Get the fetched message data.
     *
     * @return FetchedMessageData[]
     */
    public function messages(): array
    {
        return $this->map(
            fn (FetchedResponse $fetch) => $fetch->data()
        )->values()->all();
    }
}
