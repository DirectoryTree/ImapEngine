<?php

namespace DirectoryTree\ImapEngine\Collections;

use DirectoryTree\ImapEngine\Enums\ImapSpecialUse;
use DirectoryTree\ImapEngine\FolderInterface;
use Illuminate\Support\Collection;

/**
 * @template-extends Collection<array-key, FolderInterface>
 */
class FolderCollection extends Collection
{
    /**
     * Find a folder by its special use.
     */
    public function findBySpecialUse(ImapSpecialUse $specialUse): ?FolderInterface
    {
        return $this->first(function (FolderInterface $folder) use ($specialUse) {
            foreach ($folder->attributes() as $attribute) {
                if (strcasecmp($attribute, $specialUse->value) === 0) {
                    return true;
                }
            }

            return false;
        });
    }
}
