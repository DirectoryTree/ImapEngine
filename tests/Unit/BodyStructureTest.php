<?php

use DirectoryTree\ImapEngine\BodyStructureCollection;
use DirectoryTree\ImapEngine\BodyStructurePart;

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
    expect($part->description())->toBeNull();
});

test('it preserves plain content descriptions', function () {
    $listData = parseBodyStructureResponse(
        '* 1 FETCH (BODYSTRUCTURE ("application" "pdf" NIL NIL "A PDF invoice" "base64" 5000 NIL NIL NIL NIL) UID 1)'
    );

    $part = BodyStructurePart::fromListData($listData);

    expect($part->description())->toBe('A PDF invoice');
    expect($part->toArray()['description'])->toBe('A PDF invoice');
});

test('it decodes MIME encoded content descriptions', function () {
    $listData = parseBodyStructureResponse(
        '* 1 FETCH (BODYSTRUCTURE ("application" "pdf" NIL NIL "=?iso-8859-1?Q?123456_-_von_Beispiel_GmbH_vom_01.01.2025_Pauschale?= =?iso-8859-1?Q?_f=FCr_Zustellungen=5F.pdf?=" "base64" 5000 NIL NIL NIL NIL) UID 1)'
    );

    $part = BodyStructurePart::fromListData($listData);

    expect($part->description())->toBe('123456 - von Beispiel GmbH vom 01.01.2025 Pauschale für Zustellungen_.pdf');
    expect($part->toArray()['description'])->toBe('123456 - von Beispiel GmbH vom 01.01.2025 Pauschale für Zustellungen_.pdf');
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

test('it decodes continued attachment filenames', function () {
    $listData = parseBodyStructureResponse(
        '* 1 FETCH (BODYSTRUCTURE (("text" "plain" ("charset" "utf-8") NIL NIL "7bit" 100 5 NIL NIL NIL) ("application" "pdf" ("name*1" "attachment_name_part_1.pdf" "name*0" "attachment_name_part_0") NIL NIL "base64" 5000 NIL ("attachment" ("filename*1" "attachment_name_part_1.pdf" "filename*0" "attachment_name_part_0")) NIL NIL) "mixed" ("boundary" "abc") NIL NIL) UID 1)'
    );

    $collection = BodyStructureCollection::fromListData($listData);
    $attachment = $collection->attachments()[0];

    expect($attachment->filename())->toBe('attachment_name_part_0attachment_name_part_1.pdf');
    expect($attachment->parameters())->toBe([
        'name' => 'attachment_name_part_0attachment_name_part_1.pdf',
    ]);
    expect($attachment->disposition()?->parameters())->toBe([
        'filename' => 'attachment_name_part_0attachment_name_part_1.pdf',
    ]);
});

test('it decodes extended content type names when no disposition is present', function () {
    $listData = parseBodyStructureResponse(
        '* 1 FETCH (BODYSTRUCTURE (("text" "plain" ("charset" "utf-8") NIL NIL "7bit" 100 5 NIL NIL NIL) ("application" "pdf" ("name*1" "2026.pdf" "name*0*" "utf-8\'\'invoice%20") NIL NIL "base64" 5000 NIL NIL NIL NIL) "mixed" ("boundary" "abc") NIL NIL) UID 1)'
    );

    $collection = BodyStructureCollection::fromListData($listData);
    $attachment = $collection->attachments()[0];

    expect($attachment->filename())->toBe('invoice 2026.pdf');
    expect($attachment->parameters())->toBe([
        'name' => 'invoice 2026.pdf',
    ]);
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

test('it does not emit warnings when non-scalar tokens appear in message/rfc822 structure fields', function () {
    $listData = parseBodyStructureResponse(
        '* 1 FETCH (BODYSTRUCTURE ("MESSAGE" "RFC822" NIL NIL NIL "7BIT" 3456 (NIL NIL NIL NIL NIL NIL NIL NIL NIL NIL) ("TEXT" "PLAIN" ("charset" "utf-8") NIL NIL "7BIT" 100 10 NIL NIL NIL) 42) UID 1)'
    );

    set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });

    try {
        $part = BodyStructurePart::fromListData($listData);
    } finally {
        restore_error_handler();
    }

    expect($part->contentType())->toBe('message/rfc822');
    expect($part->size())->toBe(3456);
    expect($part->lines())->toBeNull();
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
