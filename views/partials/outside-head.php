<?php
// =====================================================
// views/partials/outside-head.php
// The top of every page that is not inside the app: sign-in, a shared file,
// an expired link, an error. It prints everything from the doctype up to, but
// not including, </head>, so the page can add what only it needs.
//
// Reads, all optional:
//   $pageTitle        the part before "— DayFlow"
//   $outsideStyles    module stylesheets to add (names under css/modules/)
//   $outsideTheme     'auto' (default: follow the device), 'light' or 'dark'
//   $outsideDay       weekday key for the date colour; today when omitted
//   $outsideDayColor  'on' (default) or 'off'
// =====================================================

$outsideTheme ??= 'auto';
$outsideStyles ??= [];
$themeAttr = $outsideTheme === 'auto' ? 'light' : $outsideTheme;
$dayKey = $outsideDay ?? strtolower(date('D'));
$dayColor = ($outsideDayColor ?? 'on') === 'off' ? 'off' : 'on';
$chromeColors = ['light' => '#FBFAF7', 'dark' => '#15181D'];

$sheet = static fn(string $path): string =>
    '<link rel="stylesheet" href="' . APP_URL . '/assets/css/' . $path . '.css?v=' . @filemtime(PUBLIC_ROOT . '/assets/css/' . $path . '.css') . '">';
?>
<!DOCTYPE html>
<html lang="th" data-theme="<?= h($themeAttr) ?>" data-theme-pref="<?= h($outsideTheme) ?>" data-day="<?= h($dayKey) ?>" data-daycolor="<?= h($dayColor) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, interactive-widget=resizes-content">
    <?php if ($outsideTheme === 'auto'): ?>
    <meta name="theme-color" content="<?= $chromeColors['light'] ?>" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="<?= $chromeColors['dark'] ?>" media="(prefers-color-scheme: dark)">
    <?php else: ?>
    <meta name="theme-color" content="<?= $chromeColors[$themeAttr] ?? $chromeColors['light'] ?>">
    <?php endif; ?>
    <meta name="robots" content="noindex, nofollow, noarchive">
    <link rel="icon" type="image/png" href="<?= h(APP_URL . '/assets/icons/icon-192.png') ?>">
    <title><?= isset($pageTitle) ? h($pageTitle) . ' — ' : '' ?><?= h(APP_NAME) ?></title>
    <!-- Sets the theme before the first paint, so the page never flashes the wrong one. -->
    <script src="<?= APP_URL ?>/assets/js/standalone.js?v=<?= @filemtime(PUBLIC_ROOT . '/assets/js/standalone.js') ?>"></script>
    <?php foreach (['plexthai-thai-400', 'plexsans-latin-var'] as $criticalFont): ?>
    <link rel="preload" as="font" type="font/woff2" crossorigin
        href="<?= APP_URL ?>/assets/fonts/<?= $criticalFont ?>.woff2">
    <?php endforeach; ?>
    <?php foreach (['fonts', 'tokens', 'base', 'components'] as $name) echo '    ', $sheet($name), "\n"; ?>
    <?php foreach ($outsideStyles as $name) echo '    ', $sheet('modules/' . $name), "\n"; ?>
