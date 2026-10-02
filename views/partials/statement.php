<?php
// =====================================================
// views/partials/statement.php
// A page that only says something: an error, an expired link, a missing file.
// Plain words, what to do next, and a way back to today.
//
// Reads $statement:
//   code     big figure above the title (an HTTP status), '' for none
//   title    what happened, in a sentence
//   text     what to do about it
//   note     small line above the code, e.g. "โหมดแชร์ · ดูอย่างเดียว"
//   primary  [label, href] the one main button, or null
//   back     whether to offer "ย้อนกลับ"
//   menus    [[label, href], ...] places the visitor may still go (share mode)
// and the variables outside-head.php reads.
// =====================================================

$outsideStyles = ['outside'];
$statement += ['code' => '', 'note' => '', 'primary' => null, 'back' => true, 'menus' => []];
$pageTitle ??= $statement['code'] !== '' ? $statement['code'] : $statement['title'];

require ROOT . '/views/partials/outside-head.php';
?>
</head>
<body class="stmt-page">
<main class="stmt">
    <a class="stmt-brand" href="<?= h(APP_URL) ?>/">DayFlow</a>

    <div class="stmt-body">
        <?php if ($statement['note'] !== ''): ?>
            <p class="stmt-note"><?= h($statement['note']) ?></p>
        <?php endif; ?>
        <?php if ($statement['code'] !== ''): ?>
            <p class="stmt-code" aria-hidden="true"><?= h($statement['code']) ?></p>
        <?php endif; ?>

        <h1 class="stmt-title"><?= h($statement['title']) ?></h1>
        <?php if (!empty($statement['text'])): ?>
            <p class="stmt-text"><?= h($statement['text']) ?></p>
        <?php endif; ?>

        <div class="stmt-actions">
            <?php if ($statement['primary']): ?>
                <a class="btn btn-primary btn-lg" href="<?= h($statement['primary'][1]) ?>"><?= h($statement['primary'][0]) ?></a>
            <?php endif; ?>
            <?php if ($statement['back']): ?>
                <button type="button" class="btn btn-lg" data-back="<?= h(APP_URL) ?>/">ย้อนกลับ</button>
            <?php endif; ?>
        </div>

        <?php if ($statement['menus']): ?>
            <section class="stmt-menus" aria-labelledby="stmtMenusTitle">
                <h2 class="stmt-menus-title" id="stmtMenusTitle">หน้าที่ลิงก์นี้เปิดให้ดูได้</h2>
                <ul class="ruled-list">
                    <?php foreach ($statement['menus'] as [$label, $href]): ?>
                        <li><a class="ruled-row" href="<?= h($href) ?>"><?= h($label) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
