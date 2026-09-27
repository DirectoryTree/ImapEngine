<?php

arch('interfaces use the interface suffix')
    ->expect('DirectoryTree\ImapEngine')
    ->interfaces()
    ->toHaveSuffix('Interface');
