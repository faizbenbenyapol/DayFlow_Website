<?php
// =====================================================
// tests/unit/paginator_test.php — core/Paginator.php
// =====================================================

declare(strict_types=1);

require_once ROOT . '/core/Request.php';
require_once ROOT . '/core/Paginator.php';

/** Runs a callback with $_GET set to the given query parameters. */
function withQuery(array $query, callable $fn): mixed
{
    $previous = $_GET;
    $_GET = $query;
    try {
        return $fn();
    } finally {
        $_GET = $previous;
    }
}

test('an empty request gets the default window', function (): void {
    $window = withQuery([], static fn(): array => Paginator::fromRequest());

    assertSame(Paginator::DEFAULT_LIMIT, $window['limit']);
    assertSame(0, $window['offset']);
});

test('limit and offset are read from the query', function (): void {
    $window = withQuery(['limit' => '25', 'offset' => '50'], static fn(): array => Paginator::fromRequest());

    assertSame(25, $window['limit']);
    assertSame(50, $window['offset']);
});

test('a page number becomes an offset', function (): void {
    $page3 = withQuery(['limit' => '20', 'page' => '3'], static fn(): array => Paginator::fromRequest());

    assertSame(20, $page3['limit']);
    assertSame(40, $page3['offset'], 'page 3 of 20 starts after the first two pages');

    $page1 = withQuery(['page' => '1'], static fn(): array => Paginator::fromRequest());
    assertSame(0, $page1['offset']);
});

test('an explicit offset wins over a page number', function (): void {
    // Honouring both would silently skip or repeat rows.
    $window = withQuery(['limit' => '10', 'page' => '5', 'offset' => '3'],
        static fn(): array => Paginator::fromRequest());

    assertSame(3, $window['offset']);
});

test('a caller cannot ask for an unbounded read', function (): void {
    $huge = withQuery(['limit' => '999999'], static fn(): array => Paginator::fromRequest());
    assertSame(Paginator::MAX_LIMIT, $huge['limit']);
});

test('nonsense values fall back to something usable', function (): void {
    $zero = withQuery(['limit' => '0'], static fn(): array => Paginator::fromRequest());
    assertSame(1, $zero['limit'], 'a page of nothing is not a page');

    $negative = withQuery(['limit' => '-5', 'offset' => '-20'], static fn(): array => Paginator::fromRequest());
    assertSame(1, $negative['limit']);
    assertSame(0, $negative['offset']);

    $letters = withQuery(['limit' => 'many', 'page' => 'last'], static fn(): array => Paginator::fromRequest());
    assertSame(1, $letters['limit']);
    assertSame(0, $letters['offset']);
});

test('an endpoint can ask for its own default', function (): void {
    $window = withQuery([], static fn(): array => Paginator::fromRequest(30));
    assertSame(30, $window['limit']);

    // An explicit request still wins over the endpoint's preference.
    $asked = withQuery(['limit' => '75'], static fn(): array => Paginator::fromRequest(30));
    assertSame(75, $asked['limit']);
});

test('meta reports has_more from the total when it is known', function (): void {
    $window = ['limit' => 10, 'offset' => 0];

    $more = Paginator::meta($window, 10, 55);
    assertTrue($more['has_more']);
    assertSame(55, $more['total']);

    $last = Paginator::meta(['limit' => 10, 'offset' => 50], 5, 55);
    assertFalse($last['has_more'], 'the final partial page is the end');

    $exact = Paginator::meta(['limit' => 10, 'offset' => 50], 5, 55);
    assertSame(50, $exact['offset']);
});

test('meta guesses from the page size when no total is given', function (): void {
    // Counting costs a second query, so an endpoint may skip it.
    $full = Paginator::meta(['limit' => 10, 'offset' => 0], 10);
    assertTrue($full['has_more'], 'a full page might have more behind it');
    assertArrayNotHasKey('total', $full);

    $short = Paginator::meta(['limit' => 10, 'offset' => 0], 4);
    assertFalse($short['has_more'], 'a short page is the end');
});

test('a total that lands exactly on a page boundary ends correctly', function (): void {
    // 20 rows read in pages of 10: the second page is full but there is
    // nothing after it, and guessing from the page size alone would get this
    // wrong — which is why the total is worth the extra query.
    $second = Paginator::meta(['limit' => 10, 'offset' => 10], 10, 20);
    assertFalse($second['has_more']);
});
