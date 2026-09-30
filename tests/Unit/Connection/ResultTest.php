<?php

use DirectoryTree\ImapEngine\Collections\ResponseCollection;
use DirectoryTree\ImapEngine\Connection\ImapCommand;
use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
use DirectoryTree\ImapEngine\Connection\Result;
use DirectoryTree\ImapEngine\Connection\Tokens\Atom;

test('response collections remain snapshots as the result receives more responses', function () {
    $first = new UntaggedResponse([new Atom('*'), new Atom('OK')]);
    $second = new UntaggedResponse([new Atom('*'), new Atom('BYE')]);
    $responses = new ResponseCollection([$first]);
    $result = new Result(new ImapCommand('TAG1', 'NOOP'), $responses);
    $snapshot = $result->responses();

    $responses->pop();
    $result->addResponse($second);

    expect($snapshot->all())->toBe([$first]);

    $snapshot->pop();

    expect($result->responses()->all())->toBe([$first, $second]);
});
