<?php

namespace DirectoryTree\ImapEngine;

use DirectoryTree\ImapEngine\Enums\ImapSpecialUse;

trait HasSpecialUses
{
    /**
     * Get the folder's special uses.
     *
     * @return ImapSpecialUse[]
     */
    public function specialUses(): array
    {
        return array_values(array_filter(
            ImapSpecialUse::cases(),
            fn (ImapSpecialUse $specialUse) => $this->hasSpecialUse($specialUse)
        ));
    }

    /**
     * Determine if the folder has the given special use.
     */
    public function hasSpecialUse(ImapSpecialUse $specialUse): bool
    {
        foreach ($this->flags() as $flag) {
            if (strcasecmp($flag, $specialUse->value) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the folder's flags.
     *
     * @return string[]
     */
    abstract public function flags(): array;
}
