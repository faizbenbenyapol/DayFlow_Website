<?php
// =====================================================
// tests/integration/csp_test.php
//
// The policy is only worth having while it stays enforced and while nothing
// reintroduces an inline handler. Both are easy to undo by accident, so both
// are checked here.
// =====================================================

declare(strict_types=1);

/**
 * Every script under public/assets/js, by name — including the parts a page
 * loads after its main script, which a hand-kept list would miss.
 */
function allPageScripts(): array
{
    return array_map(fn(string $p): string => basename($p, '.js'), glob(ROOT . '/public/assets/js/*.js'));
}

/** Every page a signed-in user can reach. */
const CSP_PAGES = [
    '/', '/tasks', '/notes', '/planner', '/projects', '/exercise', '/finance',
    '/subscriptions', '/files', '/settings', '/food-notes', '/calculator',
    '/ai', '/review', '/stocks', '/habits', '/bookmarks', '/quick-notes',
    '/skills', '/focus', '/transfer', '/file-tools',
];

test('the policy is enforced, not merely reported', function (TestClient $client): void {
    $headers = $client->get('/')['headers'];

    assertStringContains('Content-Security-Policy:', $headers);
    assertTrue(
        !str_contains($headers, 'Content-Security-Policy-Report-Only'),
        'report-only collects violations but stops nothing'
    );
});

test('script-src allows no inline execution', function (TestClient $client): void {
    $headers = $client->get('/')['headers'];

    preg_match('/script-src ([^;]+)/', $headers, $m);
    $scriptSrc = $m[1] ?? '';

    assertTrue($scriptSrc !== '', 'the policy must constrain scripts');
    // This is the whole point: with these absent, an injected <script> or
    // an injected on* attribute cannot run.
    assertTrue(!str_contains($scriptSrc, "'unsafe-inline'"), "script-src must not allow 'unsafe-inline'");
    assertTrue(!str_contains($scriptSrc, "'unsafe-eval'"), "script-src must not allow 'unsafe-eval'");
    assertStringContains("'nonce-", $scriptSrc, 'the page needs a nonce for its own inline scripts');
});

test('the nonce is fresh on every response', function (TestClient $client): void {
    $first  = $client->get('/')['headers'];
    $second = $client->get('/')['headers'];

    preg_match("/'nonce-([^']+)'/", $first, $a);
    preg_match("/'nonce-([^']+)'/", $second, $b);

    assertTrue(($a[1] ?? '') !== '', 'a nonce should be present');
    assertTrue($a[1] !== ($b[1] ?? ''), 'a reused nonce is a guessable nonce');
});

test('every inline script carries that response\'s nonce', function (TestClient $client): void {
    foreach (CSP_PAGES as $page) {
        $response = $client->get($page);
        if ($response['status'] !== 200) continue;

        preg_match("/'nonce-([^']+)'/", $response['headers'], $m);
        $nonce = $m[1] ?? '';
        assertTrue($nonce !== '', $page . ' should carry a nonce');

        // A <script> without src and without the nonce would simply not run.
        preg_match_all('/<script(?![^>]*\bsrc=)([^>]*)>/i', $response['body'], $tags);
        foreach ($tags[1] as $attributes) {
            assertStringContains('nonce="' . $nonce . '"', $attributes,
                $page . ' has an inline script that would be blocked');
        }
    }
});

test('no page carries an inline event handler', function (TestClient $client): void {
    foreach (CSP_PAGES as $page) {
        $response = $client->get($page);
        if ($response['status'] !== 200) continue;

        // These stopped working the moment 'unsafe-inline' went away, so one
        // creeping back in is a silently dead button.
        $found = preg_match('/\son(?:click|change|input|submit|keydown|keyup|blur|focus)\s*=/i', $response['body']);
        assertSame(0, $found, $page . ' contains an inline handler that cannot run under the policy');
    }
});

test('the served scripts generate no inline handlers either', function (TestClient $client): void {
    // Markup built in JavaScript is subject to the same policy as markup from
    // the server, and is easier to overlook.
    foreach (allPageScripts() as $script) {
        $response = $client->get('/assets/js/' . $script . '.js');
        if ($response['status'] !== 200) continue;

        $found = preg_match('/\son(?:click|change|input|submit|keydown|keyup|blur|focus)\s*=\s*["\']/i', $response['body']);
        assertSame(0, $found, $script . '.js writes an inline handler into its markup');
    }
});

test('the policy shuts the other doors too', function (TestClient $client): void {
    $headers = $client->get('/')['headers'];

    foreach ([
        "object-src 'none'"      => 'plugins are a script execution path',
        "base-uri 'self'"        => 'an injected <base> would redirect every relative URL',
        "form-action 'self'"     => 'an injected form must not post credentials elsewhere',
        "frame-ancestors 'none'" => 'the app must not be framed for clickjacking',
    ] as $directive => $why) {
        assertStringContains($directive, $headers, $why);
    }
});

test('every page that declares an action also loads the dispatcher', function (TestClient $client): void {
    // data-act is inert markup on its own. The login page shipped without
    // actions.js once and every button on it silently did nothing.
    $pages = array_merge(CSP_PAGES, ['/login']);

    foreach ($pages as $page) {
        $response = $client->get($page);
        if ($response['status'] !== 200) continue;
        if (!str_contains($response['body'], 'data-act=')) continue;

        assertStringContains('assets/js/actions.js', $response['body'],
            $page . ' carries data-act markup but never loads the dispatcher');
    }
});

test('every data-args the server sends is valid JSON', function (TestClient $client): void {
    // The dispatcher parses this attribute; anything else drops the arguments
    // on the floor and calls the handler with none.
    foreach (array_merge(CSP_PAGES, ['/login']) as $page) {
        $response = $client->get($page);
        if ($response['status'] !== 200) continue;

        preg_match_all('/data-args="([^"]*)"/', $response['body'], $matches);
        foreach ($matches[1] as $raw) {
            $decoded = html_entity_decode($raw, ENT_QUOTES, 'UTF-8');
            assertTrue(json_decode($decoded, true) !== null || $decoded === 'null',
                $page . ' has an unparseable data-args: ' . $raw);
        }
    }
});

test('the scripts write valid data-args too', function (TestClient $client): void {
    // Markup built in JavaScript is where the quoting goes wrong: a bare " ends
    // the attribute early, and `this.checked` was never JSON to begin with.
    foreach (allPageScripts() as $script) {
        $response = $client->get('/assets/js/' . $script . '.js');
        if ($response['status'] !== 200) continue;

        preg_match_all('/data-args="([^"]*)"/', $response['body'], $matches);
        foreach ($matches[1] as $raw) {
            $decoded = html_entity_decode($raw, ENT_QUOTES, 'UTF-8');
            // A template hole or a concatenated id stands in for its value.
            $decoded = preg_replace('/\$\{[^}]*\}/', '0', $decoded);
            $decoded = preg_replace("/'\s*\+.*?\+\s*'/", '0', $decoded);

            assertTrue(json_decode($decoded, true) !== null,
                $script . '.js writes an unparseable data-args: ' . $raw);
        }
    }
});
