<?php

declare(strict_types=1);

namespace SimpleKuma\Honeycomb;

use RuntimeException;

/**
 * HTTPS fetch limited to GitHub hosts. Never include() remote PHP.
 */
final class AllowlistedHttpClient
{
    private const MAX_BYTES = 20971520; // 20 MiB

    /** @var list<string> */
    private const ALLOWED_HOSTS = [
        'raw.githubusercontent.com',
        'github.com',
        'api.github.com',
        'objects.githubusercontent.com',
        'release-assets.githubusercontent.com',
        'codeload.github.com',
    ];

    /**
     * @return array{body: string, effective_url: string, http_code: int}
     */
    public function get(string $url, int $timeoutSeconds = 20): array
    {
        $this->assertHttpsAllowlisted($url);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'SimpleKuma-Honeycomb/1.0',
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json, application/octet-stream, */*',
            ],
        ]);

        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effectiveUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error !== '') {
            throw new RuntimeException('Network error: ' . $error);
        }
        if (!is_string($body)) {
            throw new RuntimeException('Empty response from catalog host.');
        }
        if (strlen($body) > self::MAX_BYTES) {
            throw new RuntimeException('Download exceeded the Honeycomb size limit.');
        }
        if ($effectiveUrl !== '') {
            $this->assertHttpsAllowlisted($effectiveUrl);
        }
        if ($httpCode !== 200) {
            throw new RuntimeException('HTTP ' . $httpCode . ' from catalog host.');
        }

        return [
            'body' => $body,
            'effective_url' => $effectiveUrl !== '' ? $effectiveUrl : $url,
            'http_code' => $httpCode,
        ];
    }

    public function assertHttpsAllowlisted(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            throw new RuntimeException('Honeycomb only fetches HTTPS URLs.');
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!in_array($host, self::ALLOWED_HOSTS, true)) {
            throw new RuntimeException('Host is not allowlisted for Honeycomb: ' . $host);
        }
    }

    public function assertUrlBelongsToRepo(string $url, string $repo): void
    {
        $this->assertHttpsAllowlisted($url);
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');

        if (in_array($host, ['objects.githubusercontent.com', 'release-assets.githubusercontent.com'], true)) {
            return;
        }

        $needle = '/' . $repo;
        if (!str_contains($path, $needle) && !str_contains($path, '/' . rawurlencode($repo))) {
            throw new RuntimeException('Download URL is not from the configured Honeycomb catalog repository.');
        }
    }
}
