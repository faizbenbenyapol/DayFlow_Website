<?php
// =====================================================
// core/AiChat.php — ตัวคุมบทสนทนาที่ผู้ใช้สั่งงานผ่าน AI
// สร้าง system prompt จากทะเบียนคำสั่งทุกครั้ง และวนให้ AI เรียกคำสั่งทีละหนึ่งจนกว่าจะตอบผู้ใช้
// =====================================================

require_once __DIR__ . '/AiCommands.php';
require_once __DIR__ . '/LlmClient.php';

final class AiChat
{
    /** จำนวนรอบสูงสุดที่ยอมให้ AI เรียกคำสั่งต่อเนื่อง */
    public const MAX_STEPS = 5;

    /**
     * system prompt ที่บอกวันนี้ รายการคำสั่งและ args รูปแบบคำตอบ และกฎการใช้ id
     *
     * @return string ข้อความ system prompt ภาษาไทย
     */
    public static function systemPrompt(): string
    {
        $commands = [];
        foreach (AiCommands::all() as $cmd) {
            /** @var array<string, string> $argSpec */
            $argSpec = $cmd['args'];
            $args = [];
            foreach ($argSpec as $name => $description) {
                $args[] = $name . ': ' . $description;
            }
            $commands[] = '- ' . $cmd['name'] . ': ' . $cmd['description']
                . ($args === [] ? ' (ไม่มี args)' : ' | args: ' . implode('; ', $args));
        }

        $thaiDays = [
            'Sunday' => 'อาทิตย์', 'Monday' => 'จันทร์', 'Tuesday' => 'อังคาร',
            'Wednesday' => 'พุธ', 'Thursday' => 'พฤหัสบดี', 'Friday' => 'ศุกร์', 'Saturday' => 'เสาร์',
        ];
        $today = date('Y-m-d');
        $day   = $thaiDays[date('l')] ?? '';

        return "คุณคือผู้ช่วยส่วนตัวในแอป DayFlow ช่วยผู้ใช้จัดการงาน นิสัย และการเงิน\n"
            . "วันนี้คือ {$today} (วัน{$day})\n\n"
            . "คำสั่งที่ใช้ได้:\n" . implode("\n", $commands) . "\n\n"
            . "ตอบกลับเป็น JSON object เดียวเท่านั้น ห้ามมีข้อความอื่นหรือ markdown รูปแบบใดรูปแบบหนึ่งต่อไปนี้:\n"
            . "{\"reply\":\"ข้อความถึงผู้ใช้\"} เมื่อจบรอบหรือยังไม่ต้องใช้คำสั่ง\n"
            . "{\"command\":\"ชื่อคำสั่ง\",\"args\":{...}} เมื่อต้องให้แอปรันคำสั่ง\n\n"
            . "กฎ:\n"
            . "- ตอบผู้ใช้เป็นภาษาไทยเสมอ\n"
            . "- ใช้คำสั่งทีละหนึ่งคำสั่งต่อรอบ แล้วรอผลของคำสั่งก่อนตัดสินใจขั้นถัดไป\n"
            . "- id ของงาน/นิสัย ใช้ได้เฉพาะ id ที่ได้จากผลของคำสั่ง list_tasks, list_habits หรือ list_finance เท่านั้น ห้ามเดา\n"
            . "- คำสั่งที่ต้องยืนยัน (เช่น delete_task) ให้เรียกได้เลย ไม่ต้องถามผู้ใช้ก่อน แอปจะแสดงปุ่มยืนยันให้ผู้ใช้เอง\n"
            . "- ถ้าผู้ใช้ขอสิ่งที่ไม่ใช่คำสั่งในรายการ ให้ตอบด้วย reply แทน";
    }

    /**
     * คุยกับ AI จนได้คำตอบสุดท้าย หรือได้คำสั่งที่ต้องให้ผู้ใช้ยืนยัน
     *
     * ถ้าคำสั่งรันสำเร็จไปแล้วแต่ AI ตอบกลับผิดรูป (เป็นข้อความธรรมดา ไม่ใช่ JSON) จะตอบผู้ใช้ตามผล
     * ที่ทำไปแล้วแทนการบอกว่าทำไม่ได้ เพราะ AI จริงชอบสรุปเป็นข้อความหลังอ่านผลคำสั่ง
     * และถ้าบอกว่าทำไม่ได้ผู้ใช้จะสั่งซ้ำจนข้อมูลซ้ำ
     *
     * @param array $cfg ค่าเดียวกับ LlmClient::chat()
     * @param int $userId ผู้ใช้ที่คำสั่งจะทำกับข้อมูลของเขาเท่านั้น
     * @param list<array{role: string, content: string}> $history บทสนทนาที่ตรวจแล้ว ข้อความสุดท้ายเป็น user
     * @return array{reply: string, pending: array<string, mixed>|null} pending มีเมื่อรอผู้ใช้กดยืนยันคำสั่ง
     * @throws Throwable เมื่อเรียกผู้ให้บริการไม่สำเร็จ (controller แปลงเป็น 502)
     */
    public static function reply(array $cfg, int $userId, array $history): array
    {
        $system = self::systemPrompt();

        // ข้อความ assistant ที่มาจากหน้าเว็บเป็นข้อความธรรมดา ต้องห่อเป็น JSON ให้ AI เห็นคำตอบเก่า
        // ของตัวเองในรูปแบบที่ถูก ไม่เช่นนั้น AI จะเลียนแบบรูปแบบผิดแล้วตอบเป็นข้อความธรรมดา
        $messages = [];
        foreach ($history as $message) {
            $messages[] = $message['role'] === 'assistant'
                ? ['role' => 'assistant', 'content' => (string)json_encode(['reply' => $message['content']], JSON_UNESCAPED_UNICODE)]
                : $message;
        }

        /** @var list<string> $done ผลของคำสั่งที่รันสำเร็จในรอบนี้ (ไม่นับที่โยน AiCommandFailed) */
        $done = [];

        for ($step = 0; $step < self::MAX_STEPS; $step++) {
            $raw  = LlmClient::chat($cfg, $system, $messages);
            $data = LlmClient::extractJson($raw);
            if ($data === null) return self::refusal($done);

            if (isset($data['reply']) && is_string($data['reply'])) {
                return ['reply' => $data['reply'], 'pending' => null];
            }

            $name = $data['command'] ?? null;
            $args = $data['args'] ?? [];
            if (!is_string($name) || !is_array($args)) return self::refusal($done);
            $cmd = AiCommands::get($name);
            if ($cmd === null) return self::refusal($done);

            if (($cmd['confirm'] ?? false) === true) {
                /** @var callable(int, array<string, mixed>): string $preview */
                $preview = $cmd['preview'];
                try {
                    $text = $preview($userId, $args);
                } catch (AiCommandFailed $e) {
                    $messages[] = ['role' => 'assistant', 'content' => $raw];
                    $messages[] = ['role' => 'user', 'content' => 'ผลของคำสั่ง ' . $name . ': ' . $e->getMessage()
                        . "\nตอบต่อเป็น JSON object เดียวตามรูปแบบที่กำหนดเท่านั้น"];
                    continue;
                }
                return [
                    'reply'   => $text,
                    'pending' => ['command' => $name, 'args' => $args, 'text' => $text],
                ];
            }

            /** @var callable(int, array<string, mixed>): string $run */
            $run = $cmd['run'];
            try {
                $result = $run($userId, $args);
                $done[] = $result;
            } catch (AiCommandFailed $e) {
                $result = $e->getMessage();
            }
            $messages[] = ['role' => 'assistant', 'content' => $raw];
            // AI จริงมักตอบสรุปเป็นข้อความธรรมดาหลังอ่านผลคำสั่ง จึงย้ำทุกครั้งให้ตอบกลับเป็น JSON
            $messages[] = ['role' => 'user', 'content' => 'ผลของคำสั่ง ' . $name . ': ' . $result
                . "\nตอบต่อเป็น JSON object เดียวตามรูปแบบที่กำหนดเท่านั้น"];
        }

        $reply = 'AI ทำหลายขั้นเกินไป ลองสั่งให้สั้นลง';
        if ($done !== []) {
            // คำสั่งถูกบันทึกไปแล้วก่อนจะครบรอบ ต้องบอกผู้ใช้ให้รู้ว่าอะไรทำไปแล้ว
            $reply .= "\nที่ทำไปแล้ว:\n" . self::resultLines($done);
        }
        return ['reply' => $reply, 'pending' => null];
    }

    /**
     * คำตอบเมื่อ AI ตอบผิดรูป: ถ้ารอบนี้มีคำสั่งที่รันสำเร็จแล้ว ให้บอกตามจริงว่าทำอะไรไปแล้ว
     * ไม่เช่นนั้นค่อยบอกว่าทำคำสั่งนี้ไม่ได้ (EDGE-15)
     *
     * @param list<string> $results ผลของคำสั่งที่รันสำเร็จในรอบนี้
     * @return array{reply: string, pending: null}
     */
    private static function refusal(array $results): array
    {
        if ($results === []) return ['reply' => 'AI ทำคำสั่งนี้ไม่ได้', 'pending' => null];
        return ['reply' => "ทำให้แล้ว:\n" . self::resultLines($results), 'pending' => null];
    }

    /**
     * รายการผลของคำสั่ง บรรทัดละรายการ
     *
     * @param list<string> $results ผลของคำสั่งที่รันสำเร็จ
     */
    private static function resultLines(array $results): string
    {
        return implode("\n", array_map(static fn(string $result): string => '- ' . $result, $results));
    }
}
