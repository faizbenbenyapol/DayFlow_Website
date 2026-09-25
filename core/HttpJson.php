<?php
// =====================================================
// core/HttpJson.php — outbound JSON over HTTP
//
// AiController and StocksController each carried a private copy of this. They
// had drifted: different timeouts, and each read a different set of error keys
// out of a failed response.
// =====================================================

final class HttpJson
{
    /**
     * Sends a request and returns the decoded JSON body.
     *
     * @param mixed $body    encoded as JSON when not null
     * @param int   $timeout seconds for the whole request
     * @throws RuntimeException on a transport error, an HTTP error status, or a non-JSON reply
     */
    public static function request(
        string $url,
        mixed $body = null,
        array $headers = [],
        string $method = 'POST',
        int $timeout = 20
    ): array {
        $ch = curl_init($url);
        $hdrs = array_merge(['Accept: application/json'], $headers);
        if ($body !== null) $hdrs[] = 'Content-Type: application/json';

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(15, $timeout),
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

        if ($raw === false) throw new RuntimeException('HTTP error: ' . $err);
        $data = json_decode($raw, true);
        if ($code >= 400) {
            $msg = is_array($data)
                ? ($data['error']['message'] ?? $data['error'] ?? $data['detail'] ?? $data['message'] ?? $data['Note'] ?? json_encode($data))
                : $raw;
            if (is_array($msg)) $msg = json_encode($msg);
            throw new RuntimeException('HTTP ' . $code . ': ' . $msg);
        }
        if (!is_array($data)) throw new RuntimeException('ตอบกลับไม่ใช่ JSON');
        return $data;
    }
}
