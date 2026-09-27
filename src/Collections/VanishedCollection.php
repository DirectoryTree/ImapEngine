<?php

namespace DirectoryTree\ImapEngine\Collections;

use DirectoryTree\ImapEngine\Support\MessageSetMatcher;
use DirectoryTree\ImapEngine\Vanished;
use Illuminate\Support\Collection;

/**
 * @extends Collection<int, Vanished>
 */
class VanishedCollection extends Collection
{
    /**
     * Filter vanished UIDs to those belonging to the given message set.
     */
    public function forMessageSet(string $set): static
    {
        $matcher = new MessageSetMatcher($set);

        return $this->map(function (Vanished $vanished) use ($matcher) {
            return new Vanished(
                array_values(array_filter($vanished->uids(), $matcher->contains(...))),
                $vanished->earlier(),
            );
        })->reject(
            fn (Vanished $vanished) => empty($vanished->uids())
        )->values();
    }
}
