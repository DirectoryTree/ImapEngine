<?php

use DirectoryTree\ImapEngine\Support\SequenceSet;
use Illuminate\Support\LazyCollection;

test('it parses individual values and ascending and descending ranges', function () {
    $values = SequenceSet::parse('1:3,9:7,5:5,4294967294:4294967295');

    expect($values)->toBeInstanceOf(LazyCollection::class);
    expect($values->all())->toBe([1, 2, 3, 9, 8, 7, 5, 4294967294, 4294967295]);
});

test('large ranges can be read lazily more than once', function () {
    $values = SequenceSet::parse('1:4294967295');

    expect($values->take(3)->all())->toBe([1, 2, 3]);
    expect($values->take(3)->all())->toBe([1, 2, 3]);
});

test('large descending ranges can be read lazily', function () {
    $values = SequenceSet::parse('4294967295:1');

    expect($values->take(3)->all())->toBe([4294967295, 4294967294, 4294967293]);
});

test('set', function () {
    expect(SequenceSet::format(5, 10))->toBe('5:10');
    expect(SequenceSet::format('5', '10'))->toBe('5:10');
    expect(SequenceSet::format(5, INF))->toBe('5:*');
    expect(SequenceSet::format([5, 10]))->toBe('5,10');
    expect(SequenceSet::format(['5', '10']))->toBe('5,10');
    expect(SequenceSet::format([5]))->toBe('5');
    expect(SequenceSet::format(5))->toBe('5');
    expect(SequenceSet::format('*'))->toBe('*');
    expect(SequenceSet::format('$'))->toBe('$');
    expect(SequenceSet::format('4294967295'))->toBe('4294967295');
});

test('set rejects invalid sequence sets', function (array|int|string $from, int|float|string|null $to) {
    expect(fn () => SequenceSet::format($from, $to))->toThrow(InvalidArgumentException::class);
})->with([
    ['1'."\r\n".'TAG2 LOGOUT', null],
    [[1, '2'."\r\n".'TAG2 LOGOUT'], null],
    [[1, '2.0', 3], null],
    ['1 2', null],
    ['1,$', null],
    ['1::2', null],
    ['1:0', null],
    ['0', null],
    [0, null],
    [-1, null],
    ['4294967296', null],
    [1, '4294967296'],
    [[], null],
]);

test('set converts consecutive values into sequence ranges', function () {
    expect(SequenceSet::format([1, 2, 3, 5, 7, 8, 9]))->toBe('1:3,5,7:9');
    expect(SequenceSet::format([9, 8, 7, 5, 3, 2, 1]))->toBe('9:7,5,3:1');
    expect(SequenceSet::format(range(16902, 15146)))->toBe('16902:15146');
    expect(SequenceSet::format([1, 3, 5]))->toBe('1,3,5');
    expect(SequenceSet::format([1, 2, 3, 8, 7, 6]))->toBe('1:3,8:6');
    expect(SequenceSet::format(['1', '2', '3', '5']))->toBe('1:3,5');
    expect(SequenceSet::format([1, '*']))->toBe('1,*');
});

test('set ignores $to when $from is a single-element array', function () {
    expect(SequenceSet::format([5], 10))->toBe('5');
});

test('set ignores $to when $from is a multi-element array', function () {
    expect(SequenceSet::format([5, 6], 10))->toBe('5:6');
});
