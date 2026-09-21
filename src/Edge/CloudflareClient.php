<?php

declare(strict_types=1);

namespace SimpleKuma\Edge;

/**
 * Minimal Cloudflare API client for Workers + KV + routes.
 */
final class CloudflareClient
{
    private string $accountId;
    private string $apiToken;
    private string $baseUrl = 'https://api.cloudflare.com/client/v4';
    private int $timeoutSeconds;
    private int $connectTimeoutSeconds;

    /**
     * @param int $timeoutSeconds Total cURL timeout (deploy/settings may use 60; campaign hooks use a short budget)
     * @param int $connectTimeoutSeconds TCP connect timeout
     */
    public function __construct(
        string $accountId,
        string $apiToken,
        int $timeoutSeconds = 60,
        int $connectTimeoutSeconds = 10
    ) {
        $this->accountId = trim($accountId);
        $this->apiToken = trim($apiToken);
        $this->timeoutSeconds = max(1, $timeoutSeconds);
        $this->connectTimeoutSeconds = max(1, $connectTimeoutSeconds);
    }

    /**
     * @return array{ok: bool, message: string, result?: mixed}
     */
    public function verifyToken(): array
    {
        $resp = $this->request('GET', '/user/tokens/verify');
        if (!($resp['success'] ?? false)) {
            return ['ok' => false, 'message' => $this->errorMessage($resp)];
        }
        return ['ok' => true, 'message' => 'Cloudflare API token verified', 'result' => $resp['result'] ?? null];
    }

    /**
     * @return array{ok: bool, message: string, namespace_id?: string}
     */
    public function ensureKvNamespace(string $title): array
    {
        $list = $this->request('GET', "/accounts/{$this->accountId}/storage/kv/namespaces");
        if ($list['success'] ?? false) {
            foreach ($list['result'] ?? [] as $ns) {
                if (($ns['title'] ?? '') === $title) {
                    return [
                        'ok' => true,
                        'message' => 'KV namespace already exists',
                        'namespace_id' => (string) ($ns['id'] ?? ''),
                    ];
                }
            }
        }

        $created = $this->request('POST', "/accounts/{$this->accountId}/storage/kv/namespaces", [
            'title' => $title,
        ]);
        if (!($created['success'] ?? false)) {
            return ['ok' => false, 'message' => $this->errorMessage($created)];
        }
        $id = (string) ($created['result']['id'] ?? '');
        if ($id === '') {
            return ['ok' => false, 'message' => 'KV namespace created but id missing'];
        }
        return ['ok' => true, 'message' => 'KV namespace created', 'namespace_id' => $id];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function putKvValue(string $namespaceId, string $key, string $value): array
    {
        $path = "/accounts/{$this->accountId}/storage/kv/namespaces/"
            . rawurlencode($namespaceId)
            . '/values/'
            . rawurlencode($key);
        $resp = $this->requestRaw('PUT', $path, $value, 'text/plain');
        if (!($resp['success'] ?? false)) {
            return ['ok' => false, 'message' => $this->errorMessage($resp)];
        }
        return ['ok' => true, 'message' => 'KV value written'];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function deleteKvValue(string $namespaceId, string $key): array
    {
        $path = "/accounts/{$this->accountId}/storage/kv/namespaces/"
            . rawurlencode($namespaceId)
            . '/values/'
            . rawurlencode($key);
        $resp = $this->request('DELETE', $path);
        if (!($resp['success'] ?? false)) {
            // Cloudflare returns success=false when key missing — treat as ok for deletes
            $errs = $resp['errors'] ?? [];
            $code = $errs[0]['code'] ?? null;
            if ($code === 10009) {
                return ['ok' => true, 'message' => 'KV key already absent'];
            }
            return ['ok' => false, 'message' => $this->errorMessage($resp)];
        }
        return ['ok' => true, 'message' => 'KV value deleted'];
    }

    /**
     * Deploy a module Worker with KV + text/secret bindings.
     *
     * @param array<string, string> $plainTextBindings name => text
     * @param array<string, string> $secretTextBindings name => secret (not readable after set)
     * @return array{ok: bool, message: string}
     */
    public function deployWorkerScript(
        string $scriptName,
        string $moduleJs,
        string $kvNamespaceId,
        string $kvBindingName,
        array $plainTextBindings = [],
        array $secretTextBindings = []
    ): array {
        $bindings = [
            [
                'type' => 'kv_namespace',
                'name' => $kvBindingName,
                'namespace_id' => $kvNamespaceId,
            ],
        ];
        foreach ($plainTextBindings as $name => $text) {
            $bindings[] = [
                'type' => 'plain_text',
                'name' => $name,
                'text' => $text,
            ];
        }
        foreach ($secretTextBindings as $name => $text) {
            $bindings[] = [
                'type' => 'secret_text',
                'name' => $name,
                'text' => $text,
            ];
        }

        $metadata = json_encode([
            'main_module' => 'index.js',
            'bindings' => $bindings,
            'compatibility_date' => '2024-09-23',
        ], JSON_UNESCAPED_SLASHES);

        if ($metadata === false) {
            return ['ok' => false, 'message' => 'Failed to encode Worker metadata'];
        }

        $boundary = '----KumaEdge' . bin2hex(random_bytes(8));
        $body = '';
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"metadata\"; filename=\"metadata.json\"\r\n";
        $body .= "Content-Type: application/json\r\n\r\n";
        $body .= $metadata . "\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"index.js\"; filename=\"index.js\"\r\n";
        $body .= "Content-Type: application/javascript+module\r\n\r\n";
        $body .= $moduleJs . "\r\n";
        $body .= "--{$boundary}--\r\n";

        $path = "/accounts/{$this->accountId}/workers/scripts/" . rawurlencode($scriptName);
        $resp = $this->requestRaw('PUT', $path, $body, "multipart/form-data; boundary={$boundary}");
        if (!($resp['success'] ?? false)) {
            return ['ok' => false, 'message' => $this->errorMessage($resp)];
        }
        return ['ok' => true, 'message' => 'Worker script deployed'];
    }

    /**
     * Delete a Worker route that matches the given pattern (if present).
     *
     * @return array{ok: bool, message: string}
     */
    public function deleteWorkerRoute(string $zoneId, string $pattern): array
    {
        $zoneId = trim($zoneId);
        if ($zoneId === '' || $pattern === '') {
            return ['ok' => true, 'message' => 'No route to delete'];
        }

        $list = $this->request('GET', "/zones/{$zoneId}/workers/routes");
        if (!($list['success'] ?? false)) {
            return ['ok' => false, 'message' => $this->errorMessage($list)];
        }

        foreach ($list['result'] ?? [] as $route) {
            if (($route['pattern'] ?? '') !== $pattern) {
                continue;
            }
            $routeId = (string) ($route['id'] ?? '');
            if ($routeId === '') {
                continue;
            }
            $deleted = $this->request('DELETE', "/zones/{$zoneId}/workers/routes/{$routeId}");
            if (!($deleted['success'] ?? false)) {
                return ['ok' => false, 'message' => $this->errorMessage($deleted)];
            }
            return ['ok' => true, 'message' => 'Worker route deleted'];
        }

        return ['ok' => true, 'message' => 'Worker route already absent'];
    }

    /**
     * @return array{ok: bool, message: string, route_id?: string}
     */
    public function ensureWorkerRoute(string $zoneId, string $pattern, string $scriptName): array
    {
        $zoneId = trim($zoneId);
        if ($zoneId === '' || $pattern === '') {
            return ['ok' => false, 'message' => 'Zone ID and route pattern are required'];
        }

        $list = $this->request('GET', "/zones/{$zoneId}/workers/routes");
        if ($list['success'] ?? false) {
            foreach ($list['result'] ?? [] as $route) {
                if (($route['pattern'] ?? '') === $pattern) {
                    $routeId = (string) ($route['id'] ?? '');
                    $update = $this->request('PUT', "/zones/{$zoneId}/workers/routes/{$routeId}", [
                        'pattern' => $pattern,
                        'script' => $scriptName,
                    ]);
                    if (!($update['success'] ?? false)) {
                        return ['ok' => false, 'message' => $this->errorMessage($update)];
                    }
                    return ['ok' => true, 'message' => 'Worker route updated', 'route_id' => $routeId];
                }
            }
        }

        $created = $this->request('POST', "/zones/{$zoneId}/workers/routes", [
            'pattern' => $pattern,
            'script' => $scriptName,
        ]);
        if (!($created['success'] ?? false)) {
            return ['ok' => false, 'message' => $this->errorMessage($created)];
        }
        return [
            'ok' => true,
            'message' => 'Worker route created',
            'route_id' => (string) ($created['result']['id'] ?? ''),
        ];
    }

    /**
     * @return array{ok: bool, message: string, zones?: list<array{id: string, name: string}>}
     */
    public function listZones(): array
    {
        $resp = $this->request('GET', '/zones?per_page=50');
        if (!($resp['success'] ?? false)) {
            return ['ok' => false, 'message' => $this->errorMessage($resp)];
        }
        $zones = [];
        foreach ($resp['result'] ?? [] as $z) {
            $zones[] = [
                'id' => (string) ($z['id'] ?? ''),
                'name' => (string) ($z['name'] ?? ''),
            ];
        }
        return ['ok' => true, 'message' => 'Zones loaded', 'zones' => $zones];
    }

    /**
     * @param array<string, mixed>|null $json
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $json = null): array
    {
        $body = null;
        $contentType = null;
        if ($json !== null) {
            $body = json_encode($json, JSON_UNESCAPED_SLASHES);
            $contentType = 'application/json';
        }
        return $this->requestRaw($method, $path, $body, $contentType);
    }

    /**
     * @return array<string, mixed>
     */
    private function requestRaw(string $method, string $path, ?string $body, ?string $contentType): array
    {
        $url = $this->baseUrl . $path;
        $ch = curl_init($url);
        if ($ch === false) {
            return ['success' => false, 'errors' => [['message' => 'curl_init failed']]];
        }

        $headers = [
            'Authorization: Bearer ' . $this->apiToken,
        ];
        if ($contentType !== null) {
            $headers[] = 'Content-Type: ' . $contentType;
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            return ['success' => false, 'errors' => [['message' => "cURL error: {$error}"]]];
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return [
                'success' => false,
                'errors' => [['message' => "Invalid JSON response (HTTP {$status})"]],
                'http_status' => $status,
            ];
        }
        $decoded['http_status'] = $status;
        return $decoded;
    }

    /**
     * @param array<string, mixed> $resp
     */
    private function errorMessage(array $resp): string
    {
        $errors = $resp['errors'] ?? [];
        if (is_array($errors) && $errors !== []) {
            $parts = [];
            foreach ($errors as $err) {
                if (is_array($err) && isset($err['message'])) {
                    $parts[] = (string) $err['message'];
                }
            }
            if ($parts !== []) {
                return implode('; ', $parts);
            }
        }
        $msg = $resp['messages'][0]['message'] ?? null;
        if (is_string($msg) && $msg !== '') {
            return $msg;
        }
        return 'Cloudflare API request failed (HTTP ' . ($resp['http_status'] ?? '?') . ')';
    }
}
