<?php
// =====================================================
// core/LlmClient.php — ส่งบทสนทนาไปให้ผู้ให้บริการ AI ที่ผู้ใช้ตั้งค่าไว้
//
// เจ้าที่ใช้ API แบบ OpenAI ต่างกันแค่ base URL กับโมเดลเริ่มต้น (ตาราง OPENAI_COMPATIBLE)
// ส่วน Gemini และ Anthropic มีรูปแบบคำขอของตัวเอง แยกฟังก์ชันไว้
// =====================================================

final class LlmClient
{
    /** ผู้ให้บริการที่คุยข้อความได้ (replicate ทำวิดีโอเท่านั้น) */
    public const PROVIDERS = ['openai', 'gemini', 'anthropic', 'kimi', 'openrouter', 'deepseek', 'mistral', 'groq', 'xai', 'together', 'qwen', 'custom'];

    /** ชื่อที่แสดงให้ผู้ใช้เห็น */
    public const LABELS = [
        'openai'     => 'OpenAI',
        'gemini'     => 'Google Gemini',
        'anthropic'  => 'Anthropic Claude',
        'kimi'       => 'Moonshot Kimi',
        'openrouter' => 'OpenRouter',
        'deepseek'   => 'DeepSeek',
        'mistral'    => 'Mistral',
        'groq'       => 'Groq',
        'xai'        => 'xAI Grok',
        'together'   => 'Together AI',
        'qwen'       => 'Qwen (Alibaba)',
        'custom'     => 'OpenAI-compatible (URL เอง)',
        'replicate'  => 'Replicate',
    ];

    /** provider => [base URL, โมเดลเริ่มต้น] ของเจ้าที่ใช้ API แบบ OpenAI */
    private const OPENAI_COMPATIBLE = [
        'openai'     => ['https://api.openai.com/v1', 'gpt-4o-mini'],
        'kimi'       => ['https://api.moonshot.cn/v1', 'moonshot-v1-8k'],
        'openrouter' => ['https://openrouter.ai/api/v1', 'google/gemini-2.0-flash-001'],
        'deepseek'   => ['https://api.deepseek.com/v1', 'deepseek-chat'],
        'mistral'    => ['https://api.mistral.ai/v1', 'mistral-small-latest'],
        'groq'       => ['https://api.groq.com/openai/v1', 'llama-3.3-70b-versatile'],
        'xai'        => ['https://api.x.ai/v1', 'grok-3-mini'],
        'together'   => ['https://api.together.xyz/v1', 'meta-llama/Llama-3.3-70B-Instruct-Turbo'],
        'qwen'       => ['https://dashscope-intl.aliyuncs.com/compatible-mode/v1', 'qwen-plus'],
    ];

    private const ANTHROPIC_MODEL = 'claude-haiku-4-5-20251001';
    private const GEMINI_MODEL    = 'gemini-2.0-flash';

    /**
     * โมเดลเริ่มต้นของผู้ให้บริการ คืน '' เมื่อผู้ใช้ต้องระบุเอง (custom หรือเจ้าที่ไม่รู้จัก)
     *
     * @param string $provider คีย์ใน PROVIDERS
     */
    public static function defaultModel(string $provider): string
    {
        if ($provider === 'anthropic') return self::ANTHROPIC_MODEL;
        if ($provider === 'gemini')    return self::GEMINI_MODEL;
        return self::OPENAI_COMPATIBLE[$provider][1] ?? '';
    }

    /** โมเดลตอบช้า; เป็นเพดานเวลาของทั้งคำขอ (วินาที) */
    private const TIMEOUT = 60;

    private const JSON_ONLY = 'You output only valid JSON matching the requested schema. No markdown, no explanations.';

    /**
     * ส่งบทสนทนาไปให้ผู้ให้บริการตาม $cfg แล้วคืนข้อความของโมเดล
     *
     * @param array $cfg รูปเดียวกับ AiKey::config(): provider, key, base_url, model (model ว่าง = ค่าเริ่มต้น)
     * @param list<array{role: string, content: string}> $messages บทสนทนาต่อจาก system
     * @throws RuntimeException ผู้ให้บริการไม่รองรับ หรือเรียกไม่สำเร็จ
     */
    public static function chat(array $cfg, string $system, array $messages, float $temperature = 0.2): string
    {
        $provider = (string)($cfg['provider'] ?? '');
        if ($provider === 'anthropic') return self::anthropic($cfg, $system, $messages, $temperature);
        if ($provider === 'gemini')    return self::gemini($cfg, $system, $messages, $temperature);
        if ($provider === 'custom' || isset(self::OPENAI_COMPATIBLE[$provider])) {
            return self::openAiCompatible($cfg, $system, $messages, $temperature);
        }
        throw new RuntimeException('Provider ไม่รองรับ: ' . $provider);
    }

    /**
     * คำสั่งเดียวจบแบบเดิม (system JSON_ONLY + user prompt)
     *
     * @throws RuntimeException เรียกไม่สำเร็จ
     */
    public static function complete(array $cfg, string $prompt): string
    {
        return self::chat($cfg, self::JSON_ONLY, [['role' => 'user', 'content' => $prompt]], 0.8);
    }

    /**
     * ตรวจว่าคีย์ใช้งานได้ ไม่ให้ผลลัพธ์ (replicate ให้ controller ตรวจเอง)
     *
     * @throws RuntimeException คีย์ไม่ผ่าน หรือติดต่อไม่ได้
     */
    public static function ping(array $cfg): void
    {
        $provider = (string)($cfg['provider'] ?? '');
        $key      = (string)($cfg['key'] ?? '');

        if ($provider === 'gemini') {
            HttpJson::request('https://generativelanguage.googleapis.com/v1beta/models?key=' . urlencode($key), null, [], 'GET');
            return;
        }
        if ($provider === 'anthropic') {
            // คำขอเล็ก ๆ 1 token พอให้ผู้ให้บริการตรวจคีย์
            HttpJson::request('https://api.anthropic.com/v1/messages', [
                'model'      => self::model($cfg, self::ANTHROPIC_MODEL),
                'max_tokens' => 1,
                'messages'   => [['role' => 'user', 'content' => 'hi']],
            ], self::anthropicHeaders($key));
            return;
        }
        if ($provider !== 'custom' && !isset(self::OPENAI_COMPATIBLE[$provider])) {
            throw new RuntimeException('Provider ไม่รองรับ: ' . $provider);
        }

        [$base, $options] = self::endpoint($cfg);
        HttpJson::request($base . '/models', null, ['Authorization: Bearer ' . $key], 'GET', 20, $options);
    }

    /**
     * แปลง error เป็นข้อความไทยที่ขึ้นต้นด้วยชื่อผู้ให้บริการ
     *
     * @param string $provider คีย์ใน PROVIDERS (ไม่รู้จัก = ใช้ชื่อนั้นตรง ๆ)
     */
    public static function explain(string $provider, Throwable $e): string
    {
        $label = self::LABELS[$provider] ?? $provider;
        $code  = (int)$e->getCode();
        $msg   = $e->getMessage();

        // AiEndpoint โยน RuntimeException ข้อความไทยก่อนยิงออกไป (ไม่มีคำนำ "HTTP")
        if (!str_starts_with($msg, 'HTTP') && $msg !== 'ตอบกลับไม่ใช่ JSON') {
            return $label . ': ' . $msg;
        }
        if ($msg === 'ตอบกลับไม่ใช่ JSON') return $label . ': เรียก AI ไม่สำเร็จ';
        if ($code === 401 || $code === 403) return $label . ': API key ไม่ถูกต้องหรือไม่มีสิทธิ์';
        if ($code === 402 || preg_match('/quota|credit|balance|insufficient|billing/i', $msg) === 1) {
            return $label . ': เครดิตหมดหรือเกินโควตา';
        }
        if ($code === 429) return $label . ': เรียกถี่เกินไป ลองใหม่อีกสักครู่';
        if ($code === 404) return $label . ': ไม่พบโมเดลหรือ URL นี้';
        if ($code === 0)   return $label . ': ติดต่อผู้ให้บริการไม่ได้';
        if ($code >= 500)  return $label . ': ระบบของผู้ให้บริการขัดข้อง';
        return $label . ': เรียก AI ไม่สำเร็จ';
    }

    /**
     * JSON object ที่โมเดลส่งมา: ทน markdown code fence และข้อความรอบ ๆ
     * คืน null เมื่อหาไม่เจอ
     *
     * @return array|null
     */
    public static function extractJson(string $raw): ?array
    {
        $s = trim($raw);
        $s = preg_replace('/^```(?:json)?\s*/i', '', $s);
        $s = preg_replace('/\s*```\s*$/', '', $s);
        $data = json_decode($s, true);
        if (is_array($data)) return $data;

        if (preg_match('/\{.*\}/s', $s, $m)) {
            $data = json_decode($m[0], true);
            if (is_array($data)) return $data;
        }
        return null;
    }

    /**
     * base URL ที่จะยิง และตัวเลือก cURL (custom ต้อง pin IP ที่ตรวจแล้ว)
     *
     * @return array{0: string, 1: array}
     * @throws RuntimeException base URL ว่างหรือไม่ผ่านการตรวจ
     */
    private static function endpoint(array $cfg): array
    {
        $provider = (string)$cfg['provider'];
        if ($provider !== 'custom') return [self::OPENAI_COMPATIBLE[$provider][0], []];

        $base = rtrim((string)($cfg['base_url'] ?? ''), '/');
        if ($base === '') throw new RuntimeException('ยังไม่ได้ตั้งค่า URL ของผู้ให้บริการ');
        return [$base, [CURLOPT_RESOLVE => [AiEndpoint::pin($base, AiEndpoint::allowPrivate())]]];
    }

    /** โมเดลที่ผู้ใช้เลือก หรือค่าเริ่มต้นของเจ้านั้น @return string */
    private static function model(array $cfg, string $fallback): string
    {
        $model = trim((string)($cfg['model'] ?? ''));
        return $model !== '' ? $model : $fallback;
    }

    /** headers ของ Anthropic @return list<string> */
    private static function anthropicHeaders(string $key): array
    {
        return ['x-api-key: ' . $key, 'anthropic-version: 2023-06-01'];
    }

    /** เรียกเจ้าที่ใช้ API แบบ OpenAI (รวม custom ที่ผู้ใช้กำหนด URL เอง) @throws RuntimeException */
    private static function openAiCompatible(array $cfg, string $system, array $messages, float $temperature): string
    {
        $provider = (string)$cfg['provider'];
        $model    = self::model($cfg, $provider === 'custom' ? '' : self::OPENAI_COMPATIBLE[$provider][1]);
        if ($model === '') throw new RuntimeException('ยังไม่ได้ตั้งค่าโมเดลของผู้ให้บริการ');

        $body = [
            'model'       => $model,
            'messages'    => array_merge([['role' => 'system', 'content' => $system]], $messages),
            'temperature' => $temperature,
        ];
        if ($provider === 'openai') $body['response_format'] = ['type' => 'json_object'];

        $headers = ['Authorization: Bearer ' . (string)($cfg['key'] ?? '')];
        if ($provider === 'openrouter') {
            $headers[] = 'HTTP-Referer: ' . APP_URL;
            $headers[] = 'X-Title: ' . APP_NAME;
        }

        [$base, $options] = self::endpoint($cfg);
        $resp = HttpJson::request($base . '/chat/completions', $body, $headers, 'POST', self::TIMEOUT, $options);
        return (string)($resp['choices'][0]['message']['content'] ?? '');
    }

    /** เรียก Anthropic Messages API @throws RuntimeException */
    private static function anthropic(array $cfg, string $system, array $messages, float $temperature): string
    {
        $resp = HttpJson::request('https://api.anthropic.com/v1/messages', [
            'model'       => self::model($cfg, self::ANTHROPIC_MODEL),
            'max_tokens'  => 2048,
            'system'      => $system,
            'messages'    => $messages,
            'temperature' => $temperature,
        ], self::anthropicHeaders((string)($cfg['key'] ?? '')), 'POST', self::TIMEOUT);
        return (string)($resp['content'][0]['text'] ?? '');
    }

    /** เรียก Gemini generateContent (assistant = role model) @throws RuntimeException */
    private static function gemini(array $cfg, string $system, array $messages, float $temperature): string
    {
        $model = self::model($cfg, self::GEMINI_MODEL);
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model)
            . ':generateContent?key=' . urlencode((string)($cfg['key'] ?? ''));

        $contents = array_map(fn(array $m): array => [
            'role'  => $m['role'] === 'assistant' ? 'model' : 'user',
            'parts' => [['text' => $m['content']]],
        ], $messages);

        $resp = HttpJson::request($url, [
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents'          => $contents,
            'generationConfig'  => ['temperature' => $temperature, 'responseMimeType' => 'application/json'],
        ], [], 'POST', self::TIMEOUT);
        return (string)($resp['candidates'][0]['content']['parts'][0]['text'] ?? '');
    }
}
