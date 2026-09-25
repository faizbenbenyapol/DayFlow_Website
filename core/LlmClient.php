<?php
// =====================================================
// core/LlmClient.php — one text prompt, any configured provider
//
// Used by the AI assistant and the stock analysis. Both controllers used to
// carry identical copies of every provider call.
// =====================================================

final class LlmClient
{
    /** Providers that can complete a text prompt. */
    public const PROVIDERS = ['openai', 'gemini', 'anthropic', 'kimi', 'openrouter'];

    /** Model calls are slow; this is the whole-request limit in seconds. */
    private const TIMEOUT = 60;

    private const JSON_ONLY = 'You output only valid JSON matching the requested schema. No markdown, no explanations.';

    /**
     * Sends $prompt to $provider and returns the model's text.
     *
     * @throws RuntimeException for an unknown provider or a failed call
     */
    public static function complete(string $provider, string $apiKey, string $prompt): string
    {
        return match ($provider) {
            'openai'     => self::openAi($apiKey, $prompt),
            'gemini'     => self::gemini($apiKey, $prompt),
            'anthropic'  => self::anthropic($apiKey, $prompt),
            'kimi'       => self::kimi($apiKey, $prompt),
            'openrouter' => self::openRouter($apiKey, $prompt),
            default      => throw new RuntimeException('Provider ไม่รองรับ: ' . $provider),
        };
    }

    /**
     * The JSON object in a model reply: tolerates a markdown code fence and
     * prose around the object. Null when there is none.
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

    private static function post(string $url, array $body, array $headers = []): array
    {
        return HttpJson::request($url, $body, $headers, 'POST', self::TIMEOUT);
    }

    private static function chatMessages(string $prompt): array
    {
        return [
            ['role' => 'system', 'content' => self::JSON_ONLY],
            ['role' => 'user', 'content' => $prompt],
        ];
    }

    private static function kimi(string $key, string $prompt): string
    {
        $resp = self::post('https://api.moonshot.cn/v1/chat/completions', [
            'model'       => 'moonshot-v1-8k',
            'messages'    => self::chatMessages($prompt),
            'temperature' => 0.3,
        ], ['Authorization: Bearer ' . $key]);
        return $resp['choices'][0]['message']['content'] ?? '';
    }

    private static function openRouter(string $key, string $prompt): string
    {
        $resp = self::post('https://openrouter.ai/api/v1/chat/completions', [
            'model'       => 'google/gemini-2.0-flash-001',
            'messages'    => self::chatMessages($prompt),
            'temperature' => 0.8,
        ], [
            'Authorization: Bearer ' . $key,
            'HTTP-Referer: ' . APP_URL,
            'X-Title: ' . APP_NAME,
        ]);
        return $resp['choices'][0]['message']['content'] ?? '';
    }

    private static function openAi(string $key, string $prompt): string
    {
        $resp = self::post('https://api.openai.com/v1/chat/completions', [
            'model'           => 'gpt-4o-mini',
            'messages'        => self::chatMessages($prompt),
            'temperature'     => 0.8,
            'response_format' => ['type' => 'json_object'],
        ], ['Authorization: Bearer ' . $key]);
        return $resp['choices'][0]['message']['content'] ?? '';
    }

    private static function gemini(string $key, string $prompt): string
    {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . urlencode($key);
        $resp = self::post($url, [
            'contents'         => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => ['temperature' => 0.9, 'responseMimeType' => 'application/json'],
        ]);
        return $resp['candidates'][0]['content']['parts'][0]['text'] ?? '';
    }

    private static function anthropic(string $key, string $prompt): string
    {
        $resp = self::post('https://api.anthropic.com/v1/messages', [
            'model'      => 'claude-haiku-4-5-20251001',
            'max_tokens' => 2048,
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ], [
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
        ]);
        return $resp['content'][0]['text'] ?? '';
    }
}
