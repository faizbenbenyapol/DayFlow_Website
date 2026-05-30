<?php
// =====================================================
// controllers/AiController.php — AI content generator
// =====================================================

require_once ROOT . '/models/AiKey.php';
require_once ROOT . '/models/AiGeneration.php';

class AiController
{
    public function index(): void
    {
        $pageTitle  = 'AI สร้างคอนเทนต์';
        $pageScript = 'ai';
        $pageStyle  = 'ai';

        require ROOT . '/views/layout/header.php';
        require ROOT . '/views/ai/index.php';
        require ROOT . '/views/layout/footer.php';
    }

    // ============================================================
    // API KEYS
    // ============================================================

    public function apiKeysList(): void
    {
        $userId = Auth::userId();
        $keys   = AiKey::listForUser($userId);
        Response::json(['keys' => $keys, 'providers' => AiKey::PROVIDERS]);
    }

    public function apiKeysSave(): void
    {
        $userId   = Auth::userId();
        $provider = Request::rawInput('provider', '');
        $apiKey   = trim((string)Request::rawInput('api_key', ''));

        if (!in_array($provider, AiKey::PROVIDERS, true)) {
            Response::json(['error' => 'ผู้ให้บริการไม่ถูกต้อง'], 422);
        }
        if ($apiKey === '') {
            AiKey::delete($userId, $provider);
            Response::json(['ok' => true, 'deleted' => true]);
        }
        if (strlen($apiKey) < 10) {
            Response::json(['error' => 'API key สั้นเกินไป'], 422);
        }
        AiKey::save($userId, $provider, $apiKey);
        Response::json(['ok' => true]);
    }

    public function apiKeysDelete(string $provider): void
    {
        $userId = Auth::userId();
        AiKey::delete($userId, $provider);
        Response::json(['ok' => true]);
    }

    public function apiKeysTest(): void
    {
        $userId   = Auth::userId();
        $provider = Request::rawInput('provider', '');
        $apiKey   = trim((string)Request::rawInput('api_key', ''));

        if (!in_array($provider, AiKey::PROVIDERS, true)) {
            Response::json(['error' => 'ผู้ให้บริการไม่ถูกต้อง'], 422);
        }
        // If no key passed, try the stored one
        if ($apiKey === '') {
            $apiKey = AiKey::get($userId, $provider);
        }
        if ($apiKey === '') {
            Response::json(['error' => 'ยังไม่มี API key'], 422);
        }

        try {
            $this->pingProvider($provider, $apiKey);
            Response::json(['ok' => true, 'message' => 'Key ใช้งานได้']);
        } catch (\Throwable $e) {
            Response::json(['error' => $e->getMessage()], 400);
        }
    }

    private function pingProvider(string $provider, string $key): void
    {
        switch ($provider) {
            case 'openai':
                $this->httpJson('https://api.openai.com/v1/models', null,
                    ['Authorization: Bearer ' . $key], 'GET');
                return;
            case 'gemini':
                $this->httpJson('https://generativelanguage.googleapis.com/v1beta/models?key=' . urlencode($key),
                    null, [], 'GET');
                return;
            case 'anthropic':
                // Small messages call with 1 token
                $this->httpJson('https://api.anthropic.com/v1/messages', [
                    'model' => 'claude-haiku-4-5-20251001',
                    'max_tokens' => 1,
                    'messages' => [['role' => 'user', 'content' => 'hi']],
                ], [
                    'x-api-key: ' . $key,
                    'anthropic-version: 2023-06-01',
                ]);
                return;
            case 'replicate':
                $this->httpJson('https://api.replicate.com/v1/account', null,
                    ['Authorization: Bearer ' . $key], 'GET');
                return;
            case 'kimi':
                $this->httpJson('https://api.moonshot.cn/v1/models', null,
                    ['Authorization: Bearer ' . $key], 'GET');
                return;
            case 'openrouter':
                $this->httpJson('https://openrouter.ai/api/v1/models', null,
                    ['Authorization: Bearer ' . $key], 'GET');
                return;
        }
        throw new \RuntimeException('Provider ไม่รองรับ');
    }

    // ============================================================
    // SCRIPT GENERATION
    // ============================================================

    public function apiGenerateScript(): void
    {
        $userId       = Auth::userId();
        $keyword      = trim((string)Request::rawInput('keyword', ''));
        $platform     = Request::rawInput('platform', 'tiktok');      // tiktok|shorts
        $style        = Request::rawInput('style', 'informative');    // informative|funny|inspiring|educational|storytelling
        $language     = Request::rawInput('language', 'th');
        $duration     = (int)Request::rawInput('duration_sec', 30);
        $provider     = Request::rawInput('provider', 'openai');
        $extraPrompt  = trim((string)Request::rawInput('extra_prompt', ''));

        if ($keyword === '') {
            Response::json(['error' => 'กรุณากรอกหัวข้อ/คีย์เวิร์ด'], 422);
        }
        $apiKey = AiKey::get($userId, $provider);
        if ($apiKey === '') {
            Response::json(['error' => 'ยังไม่ได้ตั้งค่า API key ของ ' . $provider], 422);
        }

        $prompt = $this->buildScriptPrompt($keyword, $platform, $style, $language, $duration, $extraPrompt);

        try {
            $raw = $this->callTextLlm($provider, $apiKey, $prompt);
        } catch (\Throwable $e) {
            Response::json(['error' => 'AI ตอบกลับผิดพลาด: ' . $e->getMessage()], 500);
        }

        $parsed = $this->extractJson($raw);
        if (!$parsed) {
            Response::json(['error' => 'AI ส่งผลลัพธ์ไม่ใช่ JSON ที่ถูกต้อง', 'raw' => $raw], 500);
        }
        $missing = $this->validateScriptSchema($parsed);
        if ($missing) {
            Response::json(['error' => 'ผลลัพธ์ขาดฟิลด์: ' . implode(', ', $missing), 'raw' => $parsed], 500);
        }

        $id = AiGeneration::create($userId, [
            'kind' => 'script',
            'keyword' => $keyword,
            'platform' => $platform,
            'style' => $style,
            'language' => $language,
            'duration_sec' => $duration,
            'text_provider' => $provider,
            'prompt' => $prompt,
            'result' => $parsed,
            'status' => 'completed',
        ]);

        Response::json(['ok' => true, 'id' => $id, 'result' => $parsed]);
    }

    // ============================================================
    // VIDEO GENERATION (via Replicate)
    // ============================================================

    public function apiGenerateVideo(): void
    {
        $userId   = Auth::userId();
        $prompt   = trim((string)Request::rawInput('prompt', ''));
        $model    = Request::rawInput('model', 'minimax/video-01');
        $linkedId = (int)Request::rawInput('linked_id', 0);

        if ($prompt === '') {
            Response::json(['error' => 'กรุณากรอก prompt สำหรับวิดีโอ'], 422);
        }

        $apiKey = AiKey::get($userId, 'replicate');
        if ($apiKey === '') {
            Response::json(['error' => 'ยังไม่ได้ตั้งค่า API key ของ Replicate'], 422);
        }

        try {
            $pred = $this->replicateCreate($apiKey, $model, ['prompt' => $prompt]);
        } catch (\Throwable $e) {
            Response::json(['error' => 'Replicate error: ' . $e->getMessage()], 500);
        }

        $id = AiGeneration::create($userId, [
            'kind' => 'video',
            'keyword' => substr($prompt, 0, 250),
            'video_provider' => 'replicate',
            'video_job_id' => $pred['id'] ?? '',
            'prompt' => $prompt,
            'status' => 'processing',
        ]);

        Response::json(['ok' => true, 'id' => $id, 'job_id' => $pred['id'] ?? '']);
    }

    public function apiVideoStatus(string $id): void
    {
        $userId = Auth::userId();
        $gen = AiGeneration::getById((int)$id, $userId);
        if (!$gen) Response::json(['error' => 'ไม่พบรายการ'], 404);

        // If already complete, just return
        if ($gen['status'] === 'completed' || $gen['status'] === 'failed') {
            Response::json(['status' => $gen['status'], 'video_url' => $gen['video_url'], 'error' => $gen['error_message']]);
        }

        $jobId = $gen['video_job_id'] ?? '';
        if (!$jobId) Response::json(['error' => 'ไม่พบ job id'], 400);

        $apiKey = AiKey::get($userId, 'replicate');
        if (!$apiKey) Response::json(['error' => 'ไม่พบ API key'], 400);

        try {
            $info = $this->replicateFetch($apiKey, $jobId);
        } catch (\Throwable $e) {
            Response::json(['error' => $e->getMessage()], 500);
        }

        $status = $info['status'] ?? 'processing';
        $mapped = ['succeeded' => 'completed', 'failed' => 'failed', 'canceled' => 'failed'][$status] ?? 'processing';

        $videoUrl = null;
        if ($mapped === 'completed') {
            $out = $info['output'] ?? null;
            if (is_array($out)) $videoUrl = $out[0] ?? null;
            else $videoUrl = $out;
        }

        AiGeneration::update((int)$id, $userId, [
            'status' => $mapped,
            'video_url' => $videoUrl,
            'error_message' => $info['error'] ?? null,
        ]);

        Response::json(['status' => $mapped, 'video_url' => $videoUrl, 'error' => $info['error'] ?? null]);
    }

    // ============================================================
    // HISTORY
    // ============================================================

    public function apiHistory(): void
    {
        $userId = Auth::userId();
        $items  = AiGeneration::listForUser($userId, 50);
        Response::json(['items' => $items]);
    }

    public function apiHistoryDelete(string $id): void
    {
        $userId = Auth::userId();
        AiGeneration::delete((int)$id, $userId);
        Response::json(['ok' => true]);
    }

    // ============================================================
    // PROMPT BUILDER
    // ============================================================

    private function buildScriptPrompt(string $keyword, string $platform, string $style, string $lang, int $duration, string $extra): string
    {
        $platformLabel = $platform === 'shorts' ? 'YouTube Shorts' : 'TikTok';
        $styleMap = [
            'informative'   => 'ให้ข้อมูลที่เป็นประโยชน์ กระชับ ชัดเจน',
            'funny'         => 'สนุก ตลก ติดเทรนด์ ใช้คำพูดที่เข้าถึงง่าย',
            'inspiring'     => 'สร้างแรงบันดาลใจ ฮึกเหิม ให้กำลังใจ',
            'educational'   => 'เน้นสอนให้ความรู้ อธิบายเป็นขั้นตอน',
            'storytelling'  => 'เล่าเรื่องน่าติดตาม มีจุดพีค',
        ];
        $styleLabel = $styleMap[$style] ?? $style;
        $langLabel = $lang === 'en' ? 'ภาษาอังกฤษ' : 'ภาษาไทย';
        $scenes = max(2, min(8, (int)ceil($duration / 6)));

        $extraLine = $extra ? "\nคำขอเพิ่มเติมจากผู้ใช้: $extra" : '';

        return <<<PROMPT
คุณเป็นผู้เชี่ยวชาญด้านการสร้างคอนเทนต์วิดีโอสั้นสำหรับ {$platformLabel} ช่วยสร้างสคริปต์ความยาวประมาณ {$duration} วินาที ({$scenes} ฉาก) ในสไตล์ "{$styleLabel}" เป็น{$langLabel}

หัวข้อ/คีย์เวิร์ด: {$keyword}{$extraLine}

ตอบกลับเป็น JSON เท่านั้น (ไม่มีข้อความอื่น ไม่มี markdown code block) ตามโครงสร้างนี้:
{
  "title": "ชื่อคลิปที่ดึงดูด ไม่เกิน 80 ตัวอักษร",
  "hook": "ประโยคเปิด 3 วินาทีแรกที่ต้องดึงให้คนหยุดดู",
  "script": [
    {"scene": 1, "time": "0-6s", "narration": "คำบรรยาย/บทพูด", "visual": "คำอธิบายภาพ/ฉากสำหรับ AI สร้างวิดีโอ เป็นภาษาอังกฤษ cinematic"}
  ],
  "cta": "Call to action ตอนท้ายคลิป",
  "description": "คำบรรยายใต้คลิปสำหรับโพสต์ 2-3 บรรทัด",
  "hashtags": ["#tag1", "#tag2"],
  "music_mood": "คำแนะนำมู้ดเพลงประกอบ",
  "thumbnail_idea": "ไอเดียภาพปก"
}

เนื้อหาต้องตรงกลุ่มเป้าหมาย {$platformLabel}, เร้าใจใน 3 วินาทีแรก, มี hashtag 5-10 ตัวที่เกี่ยวข้องและมีโอกาสติดเทรนด์, scene visual prompt เป็นภาษาอังกฤษทุกฉากเพื่อใช้กับ AI video generator
PROMPT;
    }

    private function validateScriptSchema(array $d): array
    {
        $required = ['title', 'hook', 'script', 'cta', 'description', 'hashtags'];
        $missing = [];
        foreach ($required as $f) {
            if (empty($d[$f])) $missing[] = $f;
        }
        if (!empty($d['script']) && !is_array($d['script'])) $missing[] = 'script';
        if (!empty($d['hashtags']) && !is_array($d['hashtags'])) $missing[] = 'hashtags';
        return $missing;
    }

    public function apiRegenerateSection(): void
    {
        $userId   = Auth::userId();
        $section  = Request::rawInput('section', '');
        $keyword  = trim((string)Request::rawInput('keyword', ''));
        $platform = Request::rawInput('platform', 'tiktok');
        $style    = Request::rawInput('style', 'informative');
        $language = Request::rawInput('language', 'th');
        $provider = Request::rawInput('provider', 'openai');
        $context  = Request::rawInput('context', '');

        if (!in_array($section, ['title', 'hashtags', 'description', 'hook', 'cta'], true)) {
            Response::json(['error' => 'section ไม่ถูกต้อง'], 422);
        }
        if ($keyword === '') {
            Response::json(['error' => 'ไม่มี keyword'], 422);
        }
        $apiKey = AiKey::get($userId, $provider);
        if ($apiKey === '') {
            Response::json(['error' => 'ยังไม่ได้ตั้งค่า API key'], 422);
        }

        $platformLabel = $platform === 'shorts' ? 'YouTube Shorts' : 'TikTok';
        $langLabel = $language === 'en' ? 'English' : 'ภาษาไทย';
        $sectionDesc = [
            'title'       => 'ชื่อคลิปที่ดึงดูด 3 ตัวเลือก (array ของ string ไม่เกิน 80 ตัวอักษร/ตัว)',
            'hashtags'    => 'แฮชแท็ก 8-12 ตัวที่เกี่ยวข้องและมีโอกาสติดเทรนด์ (array ของ string)',
            'description' => 'คำบรรยายใต้คลิป 3 ตัวเลือก 2-3 บรรทัด (array ของ string)',
            'hook'        => 'ประโยคเปิด 3 วินาทีแรก 3 ตัวเลือก (array ของ string)',
            'cta'         => 'Call to action ท้ายคลิป 3 ตัวเลือก (array ของ string)',
        ][$section];

        $ctxLine = $context ? "\nบริบทจากสคริปต์เดิม: $context" : '';
        $prompt = <<<P
สร้าง {$sectionDesc} สำหรับคลิป {$platformLabel} เกี่ยวกับ "{$keyword}" เป็น{$langLabel} สไตล์ {$style}{$ctxLine}
ตอบเป็น JSON เท่านั้น รูปแบบ: {"options": [...]}
P;

        try {
            $raw = $this->callTextLlm($provider, $apiKey, $prompt);
        } catch (\Throwable $e) {
            Response::json(['error' => $e->getMessage()], 500);
        }
        $parsed = $this->extractJson($raw);
        $options = is_array($parsed['options'] ?? null) ? $parsed['options'] : null;
        if (!$options) {
            Response::json(['error' => 'AI ส่งผลลัพธ์ไม่ถูกต้อง', 'raw' => $raw], 500);
        }
        Response::json(['ok' => true, 'options' => $options]);
    }

    private function extractJson(string $raw): ?array
    {
        // Strip markdown code fences
        $s = trim($raw);
        $s = preg_replace('/^```(?:json)?\s*/i', '', $s);
        $s = preg_replace('/\s*```\s*$/', '', $s);
        $data = json_decode($s, true);
        if (is_array($data)) return $data;
        // Try extracting first {...} block
        if (preg_match('/\{.*\}/s', $s, $m)) {
            $data = json_decode($m[0], true);
            if (is_array($data)) return $data;
        }
        return null;
    }

    // ============================================================
    // LLM CALLS
    // ============================================================

    private function callTextLlm(string $provider, string $apiKey, string $prompt): string
    {
        switch ($provider) {
            case 'openai':    return $this->callOpenAi($apiKey, $prompt);
            case 'gemini':    return $this->callGemini($apiKey, $prompt);
            case 'anthropic': return $this->callAnthropic($apiKey, $prompt);
            case 'kimi':      return $this->callKimi($apiKey, $prompt);
            case 'openrouter': return $this->callOpenRouter($apiKey, $prompt);
        }
        throw new \RuntimeException('Provider ไม่รองรับ: ' . $provider);
    }

    private function callKimi(string $key, string $prompt): string
    {
        $body = [
            'model' => 'moonshot-v1-8k',
            'messages' => [
                ['role' => 'system', 'content' => 'You output only valid JSON matching the requested schema. No markdown, no explanations.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.3,
        ];
        $resp = $this->httpJson('https://api.moonshot.cn/v1/chat/completions', $body, [
            'Authorization: Bearer ' . $key,
        ]);
        return $resp['choices'][0]['message']['content'] ?? '';
    }

    private function callOpenRouter(string $key, string $prompt): string
    {
        $body = [
            'model' => 'google/gemini-2.0-flash-001',
            'messages' => [
                ['role' => 'system', 'content' => 'You output only valid JSON matching the requested schema. No markdown, no explanations.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.8,
        ];
        $resp = $this->httpJson('https://openrouter.ai/api/v1/chat/completions', $body, [
            'Authorization: Bearer ' . $key,
            'HTTP-Referer: http://localhost',
            'X-Title: Stock Analyzer',
        ]);
        return $resp['choices'][0]['message']['content'] ?? '';
    }

    private function callOpenAi(string $key, string $prompt): string
    {
        $body = [
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'system', 'content' => 'You output only valid JSON matching the requested schema. No markdown, no explanations.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.8,
            'response_format' => ['type' => 'json_object'],
        ];
        $resp = $this->httpJson('https://api.openai.com/v1/chat/completions', $body, [
            'Authorization: Bearer ' . $key,
        ]);
        return $resp['choices'][0]['message']['content'] ?? '';
    }

    private function callGemini(string $key, string $prompt): string
    {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . urlencode($key);
        $body = [
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => [
                'temperature' => 0.9,
                'responseMimeType' => 'application/json',
            ],
        ];
        $resp = $this->httpJson($url, $body);
        return $resp['candidates'][0]['content']['parts'][0]['text'] ?? '';
    }

    private function callAnthropic(string $key, string $prompt): string
    {
        $body = [
            'model' => 'claude-haiku-4-5-20251001',
            'max_tokens' => 2048,
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ];
        $resp = $this->httpJson('https://api.anthropic.com/v1/messages', $body, [
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
        ]);
        return $resp['content'][0]['text'] ?? '';
    }

    // ============================================================
    // REPLICATE
    // ============================================================

    private function replicateCreate(string $key, string $model, array $input): array
    {
        // Use the "run a model" endpoint that takes model slug directly
        $url = 'https://api.replicate.com/v1/models/' . $model . '/predictions';
        return $this->httpJson($url, ['input' => $input], [
            'Authorization: Bearer ' . $key,
            'Prefer: respond-async',
        ]);
    }

    private function replicateFetch(string $key, string $jobId): array
    {
        $url = 'https://api.replicate.com/v1/predictions/' . urlencode($jobId);
        return $this->httpJson($url, null, ['Authorization: Bearer ' . $key], 'GET');
    }

    // ============================================================
    // HTTP
    // ============================================================

    private function httpJson(string $url, $body, array $headers = [], string $method = 'POST'): array
    {
        $ch = curl_init($url);
        $hdrs = array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER     => $hdrs,
            CURLOPT_CUSTOMREQUEST  => $method,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) throw new \RuntimeException('HTTP error: ' . $err);
        $data = json_decode($raw, true);
        if ($code >= 400) {
            $msg = is_array($data) ? ($data['error']['message'] ?? $data['error'] ?? $data['detail'] ?? json_encode($data)) : $raw;
            if (is_array($msg)) $msg = json_encode($msg);
            throw new \RuntimeException('HTTP ' . $code . ': ' . $msg);
        }
        if (!is_array($data)) throw new \RuntimeException('ตอบกลับไม่ใช่ JSON');
        return $data;
    }
}
