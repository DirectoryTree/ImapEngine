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
        return $this->first(
            fn (FolderInterface $folder) => $folder->hasSpecialUse($specialUse)
        ) ?? $this->first(
            fn (FolderInterface $folder) => in_array(
                strtolower($folder->name()),
                $specialUse->fallbackNames(),
                true
            )
        );
    }
}
