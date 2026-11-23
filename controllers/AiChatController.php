<?php
// =====================================================
// controllers/AiChatController.php — API แชทสั่งงานผู้ช่วย AI (ไม่เก็บประวัติลง DB)
// รับบทสนทนา ให้ AiChat เรียกคำสั่งจากทะเบียน และยืนยันคำสั่งที่ลบ/แก้ข้อมูลเดิม
// =====================================================

require_once ROOT . '/models/AiKey.php';
require_once ROOT . '/core/AiChat.php';

class AiChatController
{
    /**
     * POST /api/ai/chat — ส่งบทสนทนาไปให้ AI แล้วคืนข้อความตอบ หรือคำสั่งที่รอผู้ใช้ยืนยัน
     *
     * body: provider, messages[20 ข้อความท้าย] ที่ role เป็น user|assistant และข้อความสุดท้ายเป็น user
     */
    public function chat(): void
    {
        $userId   = Auth::userId();
        $provider = Request::input('provider', '');
        $label    = is_string($provider) ? (LlmClient::LABELS[$provider] ?? $provider) : '';
        $cfg      = is_string($provider) && in_array($provider, LlmClient::PROVIDERS, true)
            ? AiKey::config($userId, $provider)
            : null;
        if ($cfg === null) {
            Response::json(['error' => 'ยังไม่ได้ตั้งค่า API key ของ ' . ($label !== '' ? $label : 'ผู้ให้บริการ')], 422);
        }

        $messages = $this->validMessages(Request::list('messages'));
        if ($messages === null) {
            Response::json(['error' => 'ข้อความแชทไม่ถูกต้อง'], 422);
        }

        try {
            $answer = AiChat::reply($cfg, $userId, $messages);
        } catch (Throwable $e) {
            Response::json(['error' => LlmClient::explain((string)$provider, $e)], 502);
        }
        Response::json($answer);
    }

    /**
     * POST /api/ai/chat/confirm — รันคำสั่งที่ผู้ใช้กดยืนยันแล้ว
     *
     * body: command, args เฉพาะคำสั่งในทะเบียนที่ confirm = true
     */
    public function confirm(): void
    {
        $userId  = Auth::userId();
        $command = Request::input('command', '');
        $args    = Request::rawInput('args', []);

        $cmd = is_string($command) ? AiCommands::get($command) : null;
        if ($cmd === null || ($cmd['confirm'] ?? false) !== true) {
            Response::json(['error' => 'AI ทำคำสั่งนี้ไม่ได้'], 422);
        }
        if (!is_array($args)) {
            Response::json(['error' => 'AI ทำคำสั่งนี้ไม่ได้'], 422);
        }

        /** @var callable(int, array<string, mixed>): string $run */
        $run = $cmd['run'];
        try {
            $reply = $run($userId, $args);
        } catch (AiCommandFailed $e) {
            Response::json(['error' => $e->getMessage()], 422);
        }
        Response::json(['reply' => $reply]);
    }

    /**
     * เก็บ 20 ข้อความท้ายที่ role และ content ถูกต้อง
     *
     * @param array|null $items messages จาก body
     * @return list<array{role: string, content: string}>|null null เมื่อข้อมูลไม่ถูกต้อง
     */
    private function validMessages(?array $items): ?array
    {
        if ($items === null || $items === []) return null;
        $items = array_slice($items, -20);

        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) return null;
            $role    = $item['role'] ?? null;
            $content = $item['content'] ?? null;
            if (!is_string($role) || !in_array($role, ['user', 'assistant'], true)) return null;
            if (!is_string($content) || trim($content) === '' || mb_strlen($content) > 2000) return null;
            $out[] = ['role' => $role, 'content' => $content];
        }

        return $out !== [] && end($out)['role'] === 'user' ? $out : null;
    }
}
