<?php

use Carbon\Carbon;
use DirectoryTree\ImapEngine\Connection\ImapQueryBuilder;
use DirectoryTree\ImapEngine\Connection\RawQueryValue;
use DirectoryTree\ImapEngine\Enums\ImapSearchKey;

test('returns an empty string if no conditions are provided', function () {
    $builder = new ImapQueryBuilder;

    expect($builder->toImap())->toBe('');
});

test('compiles a single basic where condition', function () {
    $builder = new ImapQueryBuilder;

    $builder->where('subject', 'hello');

    expect($builder->toImap())->toBe('SUBJECT "hello"');
});

test('compiles a single criteria', function () {
    $builder = new ImapQueryBuilder;

    $builder->where(ImapSearchKey::All);

    expect($builder->toImap())->toBe('ALL');
});

test('compiles a single not criteria', function () {
    $builder = new ImapQueryBuilder;

    $builder->whereNot(ImapSearchKey::All);

    expect($builder->toImap())->toBe('NOT ALL');
});

test('compiles multiple AND conditions', function () {
    $builder = new ImapQueryBuilder;

    $builder
        ->where('subject', 'hello')
        ->where('from', 'me');

    expect($builder->toImap())->toBe('SUBJECT "hello" FROM "me"');
});

test('compiles an OR condition', function () {
    $builder = new ImapQueryBuilder;

    $builder
        ->where('subject', 'hello')
        ->orWhere('subject', 'world');

    expect($builder->toImap())->toBe('OR (SUBJECT "hello") (SUBJECT "world")');
});

test('compiles a NOT condition', function () {
    $builder = new ImapQueryBuilder;

    $builder->whereNot('subject', 'junk');

    expect($builder->toImap())->toBe('NOT SUBJECT "junk"');
});

test('compiles nested conditions with AND by default', function () {
    $builder = new ImapQueryBuilder;

    $builder->where('subject', 'test')->where(
        function (ImapQueryBuilder $q) {
            $q
                ->where('from', 'someone')
                ->orWhere('from', 'somebody');
        }
    );

    expect($builder->toImap())->toBe('SUBJECT "test" OR (FROM "someone") (FROM "somebody")');
});

test('compiles complex nested conditions', function () {
    $builder = new ImapQueryBuilder;

    $builder->where(function (ImapQueryBuilder $q) {
        $q
            ->where('subject', 'foo')
            ->orWhere('subject', 'bar');
    })->orWhere(function (ImapQueryBuilder $q) {
        $q
            ->where('from', 'someone')
            ->whereNot('subject', 'junk');
    });

    expect($builder->toImap())->toBe(
        'OR (OR (SUBJECT "foo") (SUBJECT "bar")) (FROM "someone" NOT SUBJECT "junk")'
    );
});

test('compiles an empty string value', function () {
    $builder = new ImapQueryBuilder;

    $builder->where('subject', ''); // empty string

    expect($builder->toImap())->toBe('SUBJECT');
});

test('compiles a null value', function () {
    $builder = new ImapQueryBuilder;

    $builder->where('subject', null); // null value

    expect($builder->toImap())->toBe('SUBJECT');
});

test('compiles a NOT condition with null value', function () {
    $builder = new ImapQueryBuilder;

    $builder->whereNot('subject', null); // null value

    expect($builder->toImap())->toBe('NOT SUBJECT');
});

test('compiles nested closure that has no conditions', function () {
    $builder = new ImapQueryBuilder;

    $builder->where('subject', 'test')
        ->where(function (ImapQueryBuilder $q) {
            // no conditions
        });

    expect($builder->toImap())->toBe('SUBJECT "test"');
});

test('compiles deeply nested closures', function () {
    $builder = new ImapQueryBuilder;

    $builder->where('subject', 'level1')
        ->orWhere(function (ImapQueryBuilder $q) {
            $q->where('subject', 'level2')
                ->orWhere(function (ImapQueryBuilder $q2) {
                    $q2->where('subject', 'level3');
                });
        });

    expect($builder->toImap())->toBe(
        'OR (SUBJECT "level1") (OR (SUBJECT "level2") (SUBJECT "level3"))'
    );
});

test('compiles multiple orWhere calls', function () {
    $builder = new ImapQueryBuilder;

    $builder->where('subject', 'first')
        ->orWhere('subject', 'second')
        ->orWhere('subject', 'third');

    expect($builder->toImap())->toBe(
        'OR (OR (SUBJECT "first") (SUBJECT "second")) (SUBJECT "third")'
    );
});

test('compiles multiple conditions on the same column', function () {
    $builder = new ImapQueryBuilder;

    $builder->where('subject', 'foo')
        ->where('subject', 'bar')
        ->orWhere('subject', 'baz');

    expect($builder->toImap())->toBe(
        'OR (SUBJECT "foo" SUBJECT "bar") (SUBJECT "baz")'
    );
});

test('escapes double quotes in search value', function () {
    $builder = new ImapQueryBuilder;

    $builder->where('subject', 'He said "Hello"');

    expect($builder->toImap())->toBe('SUBJECT "He said \\"Hello\\""');
});

test('removes newlines and carriage returns in search value', function () {
    $builder = new ImapQueryBuilder;

    $builder->where('body', "Line one\nLine two\rLine three");

    expect($builder->toImap())->toBe('BODY "Line oneLine twoLine three"');
});

test('escapes multiple special characters', function () {
    $builder = new ImapQueryBuilder;

    // Special characters: double quotes, newlines, backslash
    $value = "Foo \"Bar\"\nBaz\\Zot";

    $builder->where('subject', $value);

    expect($builder->toImap())->toBe('SUBJECT "Foo \\"Bar\\"Baz\\\\Zot"');
});

test('compiles a SINCE condition with unquoted date', function () {
    $builder = new ImapQueryBuilder;

    $builder->since(Carbon::create(2024, 4, 4));

    expect($builder->toImap())->toBe('SINCE 04-Apr-2024');
});

test('compiles a SINCE condition with quoted date', function () {
    $builder = new ImapQueryBuilder;

    $builder->where('since', Carbon::create(2024, 4, 4));

    expect($builder->toImap())->toBe('SINCE "04-Apr-2024"');
});

test('compiles raw value', function () {
    $builder = new ImapQueryBuilder;

    $builder->where('foo', new RawQueryValue('bar'));

    expect($builder->toImap())->toBe('FOO bar');
});

test('converts values from utf-8 to utf-7', function () {
    $builder = new ImapQueryBuilder;

    $builder->where('foo', 'Joué');

    expect($builder->toImap())->toBe('FOO "Jou&AOk-"');
});

test('compiles UID condition without quotes', function () {
    $builder = new ImapQueryBuilder;

    $builder->uid(2);

    expect($builder->toImap())->toBe('UID 2');
});

test('compiles multiple UID values without quotes', function () {
    $builder = new ImapQueryBuilder;

    $builder->uid([2, 3, 5]);

    expect($builder->toImap())->toBe('UID 2,3,5');
});

test('compiles UID range to infinity with from and to', function () {
    $builder = new ImapQueryBuilder;

    $builder->uid(2, INF);

    expect($builder->toImap())->toBe('UID 2:*');
});

test('compiles UID range with upper bound with array', function () {
    $builder = new ImapQueryBuilder;

    $builder->uid([2, 5]);

    expect($builder->toImap())->toBe('UID 2,5');
});

test('compiles UID range with upper bound with from and to', function () {
    $builder = new ImapQueryBuilder;

    $builder->uid(2, 5);

    expect($builder->toImap())->toBe('UID 2:5');
});

test('compiles UID range with single value', function () {
    $builder = new ImapQueryBuilder;

    $builder->uid(2);

    expect($builder->toImap())->toBe('UID 2');
});

test('compiles UID range with single value array', function () {
    $builder = new ImapQueryBuilder;

    $builder->uid([2]);

    expect($builder->toImap())->toBe('UID 2');
});

test('compiles LARGER condition without quotes', function () {
    expect((new ImapQueryBuilder)->larger(5242880)->toImap())->toBe('LARGER 5242880');
});

test('compiles SMALLER condition without quotes', function () {
    expect((new ImapQueryBuilder)->smaller(10240)->toImap())->toBe('SMALLER 10240');
});

test('compiles LARGER and SMALLER conditions together', function () {
    $builder = new ImapQueryBuilder;

    $builder->larger(1024)->smaller(1048576);

    expect($builder->toImap())->toBe('LARGER 1024 SMALLER 1048576');
});
