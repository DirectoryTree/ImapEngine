<?php

use DirectoryTree\ImapEngine\Connection\FakeStream;
use DirectoryTree\ImapEngine\Connection\ImapStreamTokenizer;
use DirectoryTree\ImapEngine\Connection\ImapToken;

test('it tokenizes', function () {
    $tokenizer = new ImapStreamTokenizer;

    $tokens = $tokenizer->tokenize(new FakeStream, 'OK [READ-WRITE] SELECT completed.');

    expect($tokens)->toBeArray();
    expect($tokens)->toHaveCount(4);

    expect($tokens[0]->type)->toBe(ImapToken::TYPE_ATOM);
    expect($tokens[0]->value)->toBe('OK');

    expect($tokens[1]->type)->toBe(ImapToken::TYPE_ATOM);
    expect($tokens[1]->value)->toBe('READ-WRITE');

    expect($tokens[2]->type)->toBe(ImapToken::TYPE_ATOM);
    expect($tokens[2]->value)->toBe('SELECT');

    expect($tokens[3]->type)->toBe(ImapToken::TYPE_ATOM);
    expect($tokens[3]->value)->toBe('completed.');
});
