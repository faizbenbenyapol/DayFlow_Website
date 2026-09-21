<?php
// =====================================================
// tests/TestClient.php — HTTP client for the integration suites
//
// Keeps a cookie jar and re-reads the CSRF token from whichever page it last
// fetched, so a test can drive the app the way a browser does.
// =====================================================

declare(strict_types=1);

final class TestClient
{
    private string $baseUrl;
    private string $cookieJar;
    private string $csrf = '';

    public function __construct(string $baseUrl)
    {
        $this->baseUrl   = rtrim($baseUrl, '/');
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'dayflow-test-');
    }

    public function __destruct()
    {
        if (is_file($this->cookieJar)) @unlink($this->cookieJar);
    }

    /** @return array{status:int, body:string, headers:string} */
    public function request(string $method, string $path, ?array $json = null): array
    {
        $ch = curl_init($this->baseUrl . $path);
        $headers = ['Accept: application/json'];

        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'X-CSRF-Token: ' . $this->csrf;
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_UNICODE));
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR      => $this->cookieJar,
            CURLOPT_COOKIEFILE     => $this->cookieJar,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 20,
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('HTTP request failed: ' . $error);
        }

        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $body = substr($raw, $headerSize);

        // A rendered page carries the next CSRF token.
        if (preg_match('/name="csrf-token" content="([^"]+)"/', $body, $m)) {
            $this->csrf = $m[1];
        }

        return ['status' => $status, 'body' => $body, 'headers' => substr($raw, 0, $headerSize)];
    }

    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    public function post(string $path, array $json): array
    {
        return $this->request('POST', $path, $json);
    }

    /**
     * Posts a file the way a browser form would, so the endpoint sees a real
     * $_FILES entry rather than a JSON blob.
     */
    public function upload(string $path, string $field, string $filename, string $contents): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'dayflow-upload-');
        file_put_contents($tmp, $contents);

        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => [$field => new CURLFile($tmp, 'text/calendar', $filename)],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR      => $this->cookieJar,
            CURLOPT_COOKIEFILE     => $this->cookieJar,
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'X-CSRF-Token: ' . $this->csrf],
            CURLOPT_TIMEOUT        => 20,
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            @unlink($tmp);
            throw new RuntimeException('Upload failed: ' . $error);
        }

        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        @unlink($tmp);

        return ['status' => $status, 'body' => substr($raw, $headerSize), 'headers' => substr($raw, 0, $headerSize)];
    }

    /**
     * Posts several files (and optional text fields) in one multipart request,
     * the way a form with <input multiple> does.
     *
     * @param array<int, array{name: string, contents: string, type?: string}> $files
     * @param array<string, string> $fields
     */
    public function uploadMany(string $path, string $field, array $files, array $fields = []): array
    {
        $temporary = [];
        $payload   = $fields;

        foreach ($files as $index => $file) {
            $tmp = tempnam(sys_get_temp_dir(), 'dayflow-upload-');
            file_put_contents($tmp, $file['contents']);
            $temporary[] = $tmp;

            // A field name ending in [] is how PHP builds $_FILES[...] as an
            // array of entries rather than a single one.
            $key = count($files) > 1 ? $field . '[' . $index . ']' : $field;
            $payload[$key] = new CURLFile($tmp, $file['type'] ?? 'application/octet-stream', $file['name']);
        }

        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR      => $this->cookieJar,
            CURLOPT_COOKIEFILE     => $this->cookieJar,
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'X-CSRF-Token: ' . $this->csrf],
            CURLOPT_TIMEOUT        => 30,
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            foreach ($temporary as $tmp) @unlink($tmp);
            throw new RuntimeException('Upload failed: ' . $error);
        }

        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        foreach ($temporary as $tmp) @unlink($tmp);

        return ['status' => $status, 'body' => substr($raw, $headerSize), 'headers' => substr($raw, 0, $headerSize)];
    }

    /** Decoded JSON body, or an empty array when the response was not JSON. */
    public function json(array $response): array
    {
        $data = json_decode($response['body'], true);
        return is_array($data) ? $data : [];
    }

    /**
     * Registers the account if it does not exist yet, then signs in. Tests run
     * against a throwaway database, so reusing one account keeps them fast.
     */
    public function login(string $username, string $password): void
    {
        $this->get('/login');
        $this->post('/api/auth/register', [
            'username'         => $username,
            'email'            => $username . '@example.test',
            'password'         => $password,
            'confirm_password' => $password,
            'display_name'     => $username,
        ]);

        $this->get('/login');
        $response = $this->post('/api/auth/login', [
            'identifier' => $username,
            'password'   => $password,
        ]);

        if ($response['status'] !== 200) {
            throw new RuntimeException('Test login failed: HTTP ' . $response['status'] . ' ' . $response['body']);
        }

        // Land on a page so the client holds a usable CSRF token.
        $this->get('/');
    }
}
