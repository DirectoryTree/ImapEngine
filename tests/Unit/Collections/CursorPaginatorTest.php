<?php

use DirectoryTree\ImapEngine\Pagination\Cursor;
use DirectoryTree\ImapEngine\Pagination\CursorPaginator;
use Illuminate\Support\Collection;

it('preserves typed cursor state through encoding', function () {
    $cursor = new Cursor(
        mailbox: 'account',
        folder: 'Inbox/Invoices + Receipts',
        uid: 15,
        maxUid: 100,
        uidValidity: 42,
        direction: 'desc',
    );
    $encoded = $cursor->encode();

    expect(Cursor::fromEncoded($encoded))->toEqual($cursor)
        ->and($encoded)->not->toMatch('/[+\/=]/');
});

it('throws when the encoded cursor cannot be restored', function (string $encoded, string $exception) {
    expect(fn () => Cursor::fromEncoded($encoded))->toThrow($exception);
})->with([
    ['!!!', JsonException::class],
    [base64_encode('not JSON'), JsonException::class],
    [base64_encode('{}'), ArgumentCountError::class],
    [base64_encode('null'), TypeError::class],
]);

it('keeps a continuation even if none of the selected messages survived fetching', function () {
    $cursor = new Cursor(mailbox: 'account', folder: 'INBOX', uid: 15, maxUid: 100, uidValidity: 42, direction: 'desc');
    $page = new CursorPaginator(new Collection, $cursor);

    expect($page->items())->toBeEmpty()
        ->and($page->hasMorePages())->toBeTrue()
        ->and($page->nextCursor())->toBe($cursor);
});

it('exposes the collection and marks a page without a cursor as complete', function () {
    $items = new Collection([['id' => 1], ['id' => 2]]);
    $page = new CursorPaginator($items);

    expect($page->items())->toBe($items)
        ->and($page->items()->pluck('id')->all())->toBe([1, 2])
        ->and($page->hasMorePages())->toBeFalse()
        ->and($page->nextCursor())->toBeNull();
});
