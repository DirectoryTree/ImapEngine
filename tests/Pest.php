<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

// pest()->extend(Tests\TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

use DirectoryTree\ImapEngine\Connection\ImapParser;
use DirectoryTree\ImapEngine\Connection\ImapTokenizer;
use DirectoryTree\ImapEngine\Connection\Responses\Data\ListData;
use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
use DirectoryTree\ImapEngine\Connection\Streams\FakeStream;
use DirectoryTree\ImapEngine\Mailbox;

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function stub(string $filename): string
{
    return __DIR__."/Stubs/$filename";
}

function mailbox(array $config = []): Mailbox
{
    return new Mailbox([
        ...$config,
        'host' => getenv('MAILBOX_HOST'),
        'port' => getenv('MAILBOX_PORT'),
        'username' => getenv('MAILBOX_USERNAME'),
        'password' => getenv('MAILBOX_PASSWORD'),
        'encryption' => getenv('MAILBOX_ENCRYPTION'),
    ]);
}

function parseBodyStructureResponse(string $response): ListData
{
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([$response]);

    $tokenizer = new ImapTokenizer($stream);
    $parser = new ImapParser($tokenizer);

    $parsed = $parser->next();

    expect($parsed)->toBeInstanceOf(UntaggedResponse::class);

    $data = $parsed->tokenAt(3);

    expect($data)->toBeInstanceOf(ListData::class);

    return $data->lookup('BODYSTRUCTURE');
}
