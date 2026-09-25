<?php
// =====================================================
// tests/unit/ai_parsing_test.php
//
// The two helpers that read what a language model sent back. Models wrap JSON
// in markdown fences, add a sentence before it, or drop a field, and none of
// that should reach the database or the page as-is.
//
// LlmClient::extractJson() is public and shared by the AI assistant and the
// stock analysis. validateScriptSchema() is private to AiController, so those
// cases reach it through reflection rather than widening the controller.
// =====================================================

declare(strict_types=1);

require_once ROOT . '/core/HttpJson.php';
require_once ROOT . '/core/LlmClient.php';
require_once ROOT . '/controllers/AiController.php';

function aiParse(string $method, mixed ...$args): mixed
{
    $reflected = new ReflectionMethod(AiController::class, $method);
    $reflected->setAccessible(true);
    return $reflected->invoke(new AiController(), ...$args);
}

test('extractJson reads a plain JSON object', function (): void {
    assertSame(['a' => 1], LlmClient::extractJson('{"a":1}'));
});

test('extractJson strips a markdown code fence', function (): void {
    // Every major model does this at least some of the time.
    assertSame(['a' => 1], LlmClient::extractJson("```json\n{\"a\":1}\n```"));
    assertSame(['a' => 1], LlmClient::extractJson("```\n{\"a\":1}\n```"));
    assertSame(['a' => 1], LlmClient::extractJson("  ```JSON  \n{\"a\":1}\n  ```  "));
});

test('extractJson finds an object buried in prose', function (): void {
    $raw = "Sure! Here is the script you asked for:\n{\"title\":\"x\"}\nHope that helps.";
    assertSame(['title' => 'x'], LlmClient::extractJson($raw));
});

test('extractJson handles a nested object and Thai text', function (): void {
    $raw = '{"title":"หัวข้อ","script":[{"scene":1,"text":"สวัสดี"}]}';
    $parsed = LlmClient::extractJson($raw);

    assertSame('หัวข้อ', $parsed['title']);
    assertSame('สวัสดี', $parsed['script'][0]['text']);
});

test('extractJson returns null when there is no object at all', function (): void {
    assertSame(null, LlmClient::extractJson('I am afraid I cannot help with that.'));
    assertSame(null, LlmClient::extractJson(''));
    assertSame(null, LlmClient::extractJson('{ this is not json }'));
});

test('validateScriptSchema accepts a complete result', function (): void {
    $complete = [
        'title'       => 'หัวข้อ',
        'hook'        => 'ประโยคเปิด',
        'script'      => [['scene' => 1, 'text' => 'เนื้อหา']],
        'cta'         => 'กดติดตาม',
        'description' => 'คำอธิบาย',
        'hashtags'    => ['#a', '#b'],
    ];

    assertSame([], aiParse('validateScriptSchema', $complete), 'nothing should be reported missing');
});

test('validateScriptSchema names every field the model left out', function (): void {
    $missing = aiParse('validateScriptSchema', ['title' => 'มีแค่หัวข้อ']);

    foreach (['hook', 'script', 'cta', 'description', 'hashtags'] as $field) {
        assertContains($field, $missing, "{$field} should be reported missing");
    }
    assertNotContains('title', $missing);
});

test('validateScriptSchema rejects a script or hashtags of the wrong shape', function (): void {
    $base = [
        'title' => 'x', 'hook' => 'x', 'cta' => 'x', 'description' => 'x',
        'script' => [['scene' => 1]], 'hashtags' => ['#a'],
    ];

    // A model that returns prose where a list belongs would otherwise be
    // stored and then rendered as a broken page.
    assertContains('script', aiParse('validateScriptSchema', ['script' => 'a single string'] + $base));
    assertContains('hashtags', aiParse('validateScriptSchema', ['hashtags' => '#a #b'] + $base));
});

test('validateScriptSchema treats an empty value as missing', function (): void {
    $base = [
        'title' => 'x', 'hook' => 'x', 'cta' => 'x', 'description' => 'x',
        'script' => [['scene' => 1]], 'hashtags' => ['#a'],
    ];

    assertContains('title', aiParse('validateScriptSchema', ['title' => ''] + $base));
    assertContains('script', aiParse('validateScriptSchema', ['script' => []] + $base));
});
