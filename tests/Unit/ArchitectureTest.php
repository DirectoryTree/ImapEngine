<?php

arch('interfaces use the interface suffix')
    ->expect('DirectoryTree\ImapEngine')
    ->interfaces()
    ->toHaveSuffix('Interface');

arch('all package methods have docblocks')
    ->expect('DirectoryTree\ImapEngine')
    ->toHaveMethodsDocumented();
