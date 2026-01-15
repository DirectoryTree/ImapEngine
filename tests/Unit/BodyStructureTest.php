<?php

use DirectoryTree\ImapEngine\BodyStructureCollection;
use DirectoryTree\ImapEngine\BodyStructurePart;
use DirectoryTree\ImapEngine\Connection\ImapParser;
use DirectoryTree\ImapEngine\Connection\ImapTokenizer;
use DirectoryTree\ImapEngine\Connection\Responses\Data\ListData;
use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
use DirectoryTree\ImapEngine\Connection\Streams\FakeStream;

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

test('it parses a simple text/plain message as BodyStructurePart', function () {
    $listData = parseBodyStructureResponse(
        '* 1 FETCH (BODYSTRUCTURE ("text" "plain" ("charset" "utf-8") NIL NIL "7bit" 100 5 NIL NIL NIL) UID 1)'
    );

    $part = BodyStructurePart::fromListData($listData);

    expect($part)->toBeInstanceOf(BodyStructurePart::class);
    expect($part->contentType())->toBe('text/plain');
    expect($part->charset())->toBe('utf-8');
    expect($part->encoding())->toBe('7bit');
    expect($part->size())->toBe(100);
    expect($part->lines())->toBe(5);
    expect($part->partNumber())->toBe('1');
});

test('it parses a multipart/alternative message as BodyStructureCollection', function () {
    $listData = parseBodyStructureResponse(
        '* 1 FETCH (BODYSTRUCTURE (("text" "plain" ("charset" "utf-8") NIL NIL "quoted-printable" 11 1 NIL NIL NIL) ("text" "html" ("charset" "utf-8") NIL NIL "quoted-printable" 18 1 NIL NIL NIL) "alternative" ("boundary" "Aq14h3UL") NIL NIL) UID 1)'
    );

    $collection = BodyStructureCollection::fromListData($listData);

    expect($collection)->toBeInstanceOf(BodyStructureCollection::class);
    expect($collection->contentType())->toBe('multipart/alternative');
    expect($collection->parts())->toHaveCount(2);

    $textPart = $collection->text();
    expect($textPart)->not->toBeNull();
    expect($textPart->contentType())->toBe('text/plain');
    expect($textPart->partNumber())->toBe('1');

    $htmlPart = $collection->html();
    expect($htmlPart)->not->toBeNull();
    expect($htmlPart->contentType())->toBe('text/html');
    expect($htmlPart->partNumber())->toBe('2');
});

test('it flattens all parts in a collection', function () {
    $listData = parseBodyStructureResponse(
        '* 1 FETCH (BODYSTRUCTURE (("text" "plain" ("charset" "utf-8") NIL NIL "7bit" 100 5 NIL NIL NIL) ("text" "html" ("charset" "utf-8") NIL NIL "7bit" 200 10 NIL NIL NIL) "alternative" ("boundary" "abc") NIL NIL) UID 1)'
    );

    $collection = BodyStructureCollection::fromListData($listData);
    $parts = $collection->flatten();

    expect($parts)->toHaveCount(2);
    expect($parts[0]->isText())->toBeTrue();
    expect($parts[1]->isHtml())->toBeTrue();
});

test('it finds part by part number in a collection', function () {
    $listData = parseBodyStructureResponse(
        '* 1 FETCH (BODYSTRUCTURE (("text" "plain" ("charset" "utf-8") NIL NIL "7bit" 100 5 NIL NIL NIL) ("text" "html" ("charset" "utf-8") NIL NIL "7bit" 200 10 NIL NIL NIL) "alternative" ("boundary" "abc") NIL NIL) UID 1)'
    );

    $collection = BodyStructureCollection::fromListData($listData);

    $part = $collection->find('2');
    expect($part)->not->toBeNull();
    expect($part->isHtml())->toBeTrue();

    $notFound = $collection->find('99');
    expect($notFound)->toBeNull();
});

test('it detects attachments in a collection', function () {
    $listData = parseBodyStructureResponse(
        '* 1 FETCH (BODYSTRUCTURE (("text" "plain" ("charset" "utf-8") NIL NIL "7bit" 100 5 NIL NIL NIL) ("application" "pdf" ("name" "document.pdf") NIL NIL "base64" 5000 NIL ("attachment" ("filename" "document.pdf")) NIL NIL) "mixed" ("boundary" "abc") NIL NIL) UID 1)'
    );

    $collection = BodyStructureCollection::fromListData($listData);

    expect($collection->hasAttachments())->toBeTrue();
    expect($collection->attachmentCount())->toBe(1);

    $attachments = $collection->attachments();
    expect($attachments[0]->filename())->toBe('document.pdf');
    expect($attachments[0]->contentType())->toBe('application/pdf');
});

test('it converts BodyStructurePart to array', function () {
    $listData = parseBodyStructureResponse(
        '* 1 FETCH (BODYSTRUCTURE ("text" "plain" ("charset" "utf-8") NIL NIL "7bit" 100 5 NIL NIL NIL) UID 1)'
    );

    $part = BodyStructurePart::fromListData($listData);

    $array = $part->toArray();

    expect($array)->toBeArray();
    expect($array['type'])->toBe('text');
    expect($array['subtype'])->toBe('plain');
    expect($array['content_type'])->toBe('text/plain');
});

test('it converts BodyStructureCollection to array', function () {
    $listData = parseBodyStructureResponse(
        '* 1 FETCH (BODYSTRUCTURE (("text" "plain" ("charset" "utf-8") NIL NIL "7bit" 100 5 NIL NIL NIL) ("text" "html" ("charset" "utf-8") NIL NIL "7bit" 200 10 NIL NIL NIL) "alternative" ("boundary" "abc") NIL NIL) UID 1)'
    );

    $collection = BodyStructureCollection::fromListData($listData);
    $array = $collection->toArray();

    expect($array)->toBeArray();
    expect($array['content_type'])->toBe('multipart/alternative');
    expect($array['subtype'])->toBe('alternative');
    expect($array['parts'])->toHaveCount(2);
});

test('it identifies inline disposition in body structure part', function () {
    $listData = parseBodyStructureResponse(
        '* 1 FETCH (BODYSTRUCTURE (("text" "html" ("charset" "utf-8") NIL NIL "7bit" 100 5 NIL NIL NIL) ("image" "png" ("name" "logo.png") "<cid123>" NIL "base64" 1000 NIL ("inline" ("filename" "logo.png")) NIL NIL) "related" ("boundary" "abc") NIL NIL) UID 1)'
    );

    $collection = BodyStructureCollection::fromListData($listData);
    $parts = $collection->flatten();

    $imagePart = $parts[1];
    expect($imagePart->isInline())->toBeTrue();
    expect($imagePart->isAttachment())->toBeFalse();
    expect($imagePart->id())->toBe('<cid123>');
});

test('it makes BodyStructureCollection countable', function () {
    $listData = parseBodyStructureResponse(
        '* 1 FETCH (BODYSTRUCTURE (("text" "plain" ("charset" "utf-8") NIL NIL "7bit" 100 5 NIL NIL NIL) ("text" "html" ("charset" "utf-8") NIL NIL "7bit" 200 10 NIL NIL NIL) "alternative" ("boundary" "abc") NIL NIL) UID 1)'
    );

    $collection = BodyStructureCollection::fromListData($listData);

    expect(count($collection))->toBe(2);
});

test('it makes BodyStructureCollection iterable', function () {
    $listData = parseBodyStructureResponse(
        '* 1 FETCH (BODYSTRUCTURE (("text" "plain" ("charset" "utf-8") NIL NIL "7bit" 100 5 NIL NIL NIL) ("text" "html" ("charset" "utf-8") NIL NIL "7bit" 200 10 NIL NIL NIL) "alternative" ("boundary" "abc") NIL NIL) UID 1)'
    );

    $collection = BodyStructureCollection::fromListData($listData);
    $parts = [];

    foreach ($collection as $part) {
        $parts[] = $part;
    }

    expect($parts)->toHaveCount(2);
    expect($parts[0])->toBeInstanceOf(BodyStructurePart::class);
    expect($parts[1])->toBeInstanceOf(BodyStructurePart::class);
});
