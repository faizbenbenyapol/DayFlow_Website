<?php
// =====================================================
// tests/unit/ai_commands_test.php — ทะเบียนคำสั่ง AI (REQ-26)
// ตรวจว่าไฟล์คำสั่งทุกไฟล์โหลดได้ ครบคีย์ และ prompt ที่ส่งให้ AI รู้จักทุกคำสั่ง
// =====================================================

declare(strict_types=1);

require_once ROOT . '/core/AiCommands.php';
require_once ROOT . '/core/AiChat.php';

test('every command file loads with the required keys and a matching name', function (): void {
    $files = glob(ROOT . '/core/ai-commands/*.php') ?: [];
    $all   = AiCommands::all();

    assertSame(count($files), count($all), 'every file in core/ai-commands should become one command');
    foreach ($files as $file) {
        $name = basename($file, '.php');
        assertArrayHasKey($name, $all);
    }

    foreach ($all as $name => $cmd) {
        assertSame($name, $cmd['name'] ?? null);
        assertTrue(is_string($cmd['description']) && $cmd['description'] !== '', "{$name} needs a description");
        assertTrue(is_array($cmd['args']), "{$name} needs an args map");
        assertTrue(is_bool($cmd['confirm']), "{$name} needs a boolean confirm");
        assertTrue(is_callable($cmd['run']), "{$name} needs a run callable");
    }
});

test('every destructive command has a preview', function (): void {
    $confirming = 0;
    foreach (AiCommands::all() as $name => $cmd) {
        if ($cmd['confirm'] !== true) continue;
        $confirming++;
        assertTrue(is_callable($cmd['preview'] ?? null), "{$name} must preview before the user confirms");
    }
    assertTrue($confirming > 0, 'at least delete_task should need confirmation');
});

test('get returns a command by name and null for an unknown one', function (): void {
    $add = AiCommands::get('add_task');
    assertTrue($add !== null && $add['name'] === 'add_task');
    assertSame(null, AiCommands::get('drop_everything'));
});

test('the system prompt lists every command and today', function (): void {
    $prompt = AiChat::systemPrompt();

    foreach (array_keys(AiCommands::all()) as $name) {
        assertStringContains($name, $prompt, "the AI should be told about {$name}");
    }
    assertStringContains(date('Y-m-d'), $prompt, 'the prompt should carry the server date');
});
