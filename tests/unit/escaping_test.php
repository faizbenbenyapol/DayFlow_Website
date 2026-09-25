<?php
// =====================================================
// tests/unit/escaping_test.php
//
// Static guards for the front end, which has no test runner of its own.
// =====================================================

declare(strict_types=1);

/** Page scripts: the JS bundles plus the views that carry inline script. */
function frontEndSources(): array
{
    $files = array_merge(glob(ROOT . '/assets/js/*.js'), glob(ROOT . '/views/*/*.php'));
    return array_values(array_filter($files, fn($f) => basename($f) !== 'html.js'));
}

test('jsonForScript cannot close the script tag it is written into', function (): void {
    $out = jsonForScript(['t' => '</script><b x=\'1\' y="2">&']);

    assertFalse(str_contains($out, '</'), 'no literal "</"');
    assertFalse(str_contains($out, '<'), 'no literal "<"');
    assertFalse(str_contains($out, '&'), 'no literal "&"');
    assertSame(['t' => '</script><b x=\'1\' y="2">&'], json_decode($out, true), 'and it still decodes to the same value');
    assertSame('"ภาษาไทย"', jsonForScript('ภาษาไทย'), 'Thai stays readable');
});

test('no page script defines its own HTML escaper', function (): void {
    // One escaper, in assets/js/html.js. The private copies disagreed on which
    // characters to escape, and two of them missed quotes inside attributes.
    $pattern = '/(function\s+(escHtml\w*|escapeHtml|escapeAttr|esc|escape)\s*\(|const\s+\w*[Ee]scape\w*\s*=)/';
    $offenders = [];
    foreach (frontEndSources() as $file) {
        if (preg_match_all($pattern, file_get_contents($file), $m)) {
            $offenders[] = basename($file) . ': ' . implode(', ', $m[0]);
        }
    }
    assertSame([], $offenders);
});

test('login and the other standalone pages do not rely on the shared escaper', function (): void {
    // They do not include views/layout/header.php, so html.js is not there.
    foreach (['auth/login.php', 'share/index.php', 'share/expired.php'] as $view) {
        $src = file_get_contents(ROOT . '/views/' . $view);
        if (str_contains($src, 'header.php')) continue;
        assertFalse((bool)preg_match('/\bescHtml\(|\bcssColor\(/', $src), "{$view} calls the escaper without loading it");
    }
});
