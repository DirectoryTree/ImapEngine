<?php

use DirectoryTree\ImapEngine\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

test('it returns the correct items', function () {
    $items = new Collection(['Item 1', 'Item 2', 'Item 3']);

    $paginator = new LengthAwarePaginator($items, total: 3, perPage: 3, currentPage: 1);

    expect($paginator->items())->toBe($items);
});

test('it calculates the total correctly', function () {
    $paginator = new LengthAwarePaginator(new Collection, total: 25, perPage: 5, currentPage: 1);

    expect($paginator->total())->toBe(25);
});

test('it calculates the per page correctly', function () {
    $paginator = new LengthAwarePaginator(new Collection, total: 30, perPage: 5, currentPage: 1);

    expect($paginator->perPage())->toBe(5);
});

test('it can determine the current page', function () {
    $paginator = new LengthAwarePaginator(new Collection, total: 30, perPage: 5, currentPage: 2);

    expect($paginator->currentPage())->toBe(2);
});

test('it calculates the last page', function () {
    $paginator = new LengthAwarePaginator(new Collection, total: 30, perPage: 5, currentPage: 1);

    expect($paginator->lastPage())->toBe(6);
});

test('it can tell if there are enough items for multiple pages', function () {
    $paginator = new LengthAwarePaginator(new Collection, total: 6, perPage: 5, currentPage: 1);

    expect($paginator->hasPages())->toBeTrue();
});

test('it can detect if there are more pages', function () {
    $paginator = new LengthAwarePaginator(new Collection, total: 10, perPage: 5, currentPage: 1);

    expect($paginator->hasMorePages())->toBeTrue();
});

test('it provides a correct next page url', function () {
    $paginator = new LengthAwarePaginator(new Collection, total: 10, perPage: 5, currentPage: 1, path: 'http://example.com/users');

    expect($paginator->nextPageUrl())->toBe('http://example.com/users?page=2');
});

test('it provides a correct previous page url', function () {
    $paginator = new LengthAwarePaginator(new Collection, total: 10, perPage: 5, currentPage: 2, path: 'http://example.com/users');

    expect($paginator->previousPageUrl())->toBe('http://example.com/users?page=1');
});

test('it returns null for next page url when on last page', function () {
    $paginator = new LengthAwarePaginator(new Collection, total: 10, perPage: 5, currentPage: 2, path: 'http://example.com/users');

    expect($paginator->nextPageUrl())->toBeNull();
});

test('it returns null for previous page url when on first page', function () {
    $paginator = new LengthAwarePaginator(new Collection, total: 10, perPage: 5, currentPage: 1, path: 'http://example.com/users');

    expect($paginator->previousPageUrl())->toBeNull();
});

test('it returns an array representation', function () {
    $paginator = new LengthAwarePaginator(new Collection(['Item 1', 'Item 2']), total: 2, perPage: 2, currentPage: 1, path: 'http://example.com/users');

    $array = $paginator->toArray();

    expect($array)->toMatchArray([
        'path' => 'http://example.com/users',
        'data' => ['Item 1', 'Item 2'],
        'total' => 2,
        'per_page' => 2,
        'last_page' => 1,
        'current_page' => 1,
        'from' => 1,
        'to' => 2,
        'first_page_url' => 'http://example.com/users?page=1',
        'last_page_url' => 'http://example.com/users?page=1',
        'next_page_url' => null,
        'prev_page_url' => null,
    ]);
});

test('it preserves existing query parameters in the next page url', function () {
    $paginator = new LengthAwarePaginator(
        new Collection,
        total: 10,
        perPage: 5,
        currentPage: 1,
        path: 'http://example.com/users',
        query: ['foo' => 'bar']
    );

    expect($paginator->nextPageUrl())->toBe('http://example.com/users?foo=bar&page=2');
});
