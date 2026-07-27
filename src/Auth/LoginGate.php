<?php

declare(strict_types=1);

namespace SimpleKuma\Auth;

use mysqli;
use SimpleKuma\Settings\SettingsManager;

/**
 * Optional login-page privacy gate.
 *
 * When enabled, auth pages require a secret query token (configurable param,
 * default ?mv=...) or a short-lived signed cookie. Unauthorized visitors are
 * redirected to a decoy URL (custom if set, otherwise https://www.google.com).
 */
class LoginGate
{
    public const DEFAULT_PARAM_NAME = 'mv';
    public const COOKIE_NAME = 'login_gate_pass';
    public const FALLBACK_DECOY_URL = 'https://www.google.com';

    /** Query params reserved so the gate does not collide with auth flows. */
    private const RESERVED_PARAMS = [
        'token', 'page', 'action', 'csrf_token', 'email', 'username', 'password', 'remember',
    ];

    private const COOKIE_LIFETIME = 86400; // 24 hours
    private const SETTING_ENABLED = 'login_gate_enabled';
    private const SETTING_TOKEN_HASH = 'login_gate_token_hash';
    private const SETTING_REDIRECT_URL = 'login_gate_redirect_url';
    private const SETTING_PARAM = 'login_gate_param';

    private ?SettingsManager $settings = null;

    public function isEnabled(mysqli $db): bool
    {
        return $this->settings($db)->get(self::SETTING_ENABLED, '0') === '1'
            && $this->getTokenHash($db) !== '';
    }

    public function hasTokenConfigured(mysqli $db): bool
    {
        return $this->getTokenHash($db) !== '';
    }

    public function getTokenHash(mysqli $db): string
    {
        return trim((string) $this->settings($db)->get(self::SETTING_TOKEN_HASH, ''));
    }

    public function getCustomRedirectUrl(mysqli $db): string
    {
        return trim((string) $this->settings($db)->get(self::SETTING_REDIRECT_URL, ''));
    }

    /**
     * Query parameter name for the secret token (e.g. mv → login.php?mv=...).
     */
    public function getParamName(mysqli $db): string
    {
        $param = trim((string) $this->settings($db)->get(self::SETTING_PARAM, self::DEFAULT_PARAM_NAME));
        if (!$this->isValidParamName($param)) {
            return self::DEFAULT_PARAM_NAME;
        }

        return $param;
    }

    /**
     * Decoy URL for blocked visitors. Custom URL if valid; otherwise Google.
     */
    public function getDecoyUrl(mysqli $db): string
    {
        $custom = $this->getCustomRedirectUrl($db);
        if ($custom !== '' && $this->isValidRedirectUrl($custom)) {
            return $custom;
        }

        return self::FALLBACK_DECOY_URL;
    }

    public function validateAccess(mysqli $db): bool
    {
        if (!$this->isEnabled($db)) {
            return true;
        }

        $param = $this->getParamName($db);
        $tokenHash = $this->getTokenHash($db);
        $provided = trim((string) ($_GET[$param] ?? ''));

        // Explicit query token present: must be correct (do not fall through to cookie).
        if ($provided !== '') {
            return $this->verifyToken($provided, $tokenHash);
        }

        // No query token: allow a previously issued pass cookie (POST / bookmarked return).
        return $this->hasValidPassCookie();
    }

    /**
     * Redirect blocked visitors to the decoy URL (no auth HTML leaked).
     */
    public function enforceOrRedirect(mysqli $db): void
    {
        header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet', true);
        header('Location: ' . $this->getDecoyUrl($db), true, 302);
        exit;
    }

    /**
     * Call after access is validated (token or cookie) so POST works without the query param.
     */
    public function issuePassCookie(): void
    {
        $expires = time() + self::COOKIE_LIFETIME;
        $value = $this->signCookiePayload($expires);
        $this->setCookie(self::COOKIE_NAME, $value, $expires);
    }

    public function clearPassCookie(): void
    {
        $this->setCookie(self::COOKIE_NAME, '', time() - 3600);
    }

    public function hashToken(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public function verifyToken(string $plain, string $hash): bool
    {
        if ($plain === '' || $hash === '') {
            return false;
        }

        return hash_equals($hash, $this->hashToken($plain));
    }

    public function isValidParamName(string $param): bool
    {
        $param = trim($param);
        if ($param === '' || strlen($param) > 32) {
            return false;
        }

        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $param)) {
            return false;
        }

        return !in_array(strtolower($param), self::RESERVED_PARAMS, true);
    }

    public function isValidRedirectUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2048) {
            return false;
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);
        return is_string($host) && $host !== '';
    }

    /**
     * Build public login URL. Plaintext token only when caller still has it (e.g. just saved).
     */
    public function buildLoginUrl(mysqli $db, ?string $plainToken = null): string
    {
        $base = defined('APP_BASE_URL') ? rtrim((string) APP_BASE_URL, '/') : '';
        $url = $base . '/login.php';
        if ($plainToken !== null && $plainToken !== '') {
            $url .= '?' . $this->getParamName($db) . '=' . rawurlencode($plainToken);
        }

        return $url;
    }

    /**
     * Persist gate settings. Returns ['success' => bool, 'errors' => [...], 'plain_token' => ?string].
     *
     * @param array{enabled?: bool, token?: string, param?: string, redirect_url?: string, clear_token?: bool} $input
     * @return array{success: bool, errors: array<string, string>, plain_token: ?string}
     */
    public function saveSettings(mysqli $db, array $input): array
    {
        $errors = [];
        $enabled = !empty($input['enabled']);
        // Accept either field name (UI uses login_gate_secret to avoid password-manager stripping)
        $token = trim((string) ($input['token'] ?? $input['secret'] ?? ''));
        $param = trim((string) ($input['param'] ?? self::DEFAULT_PARAM_NAME));
        if ($param === '') {
            $param = self::DEFAULT_PARAM_NAME;
        }
        $redirectUrl = trim((string) ($input['redirect_url'] ?? ''));
        $clearToken = !empty($input['clear_token']);
        $existingHash = $this->getTokenHash($db);
        $previousParam = $this->getParamName($db);
        $plainToReveal = null;

        if (!$this->isValidParamName($param)) {
            $errors['login_gate_param'] = 'Parameter must start with a letter, use only letters/numbers/underscores (max 32), and cannot be a reserved name.';
        }

        if ($redirectUrl !== '' && !$this->isValidRedirectUrl($redirectUrl)) {
            $errors['login_gate_redirect_url'] = 'Enter a valid http(s) URL, or leave blank to use Google.';
        }

        if ($token !== '') {
            if (strlen($token) < 8) {
                $errors['login_gate_token'] = 'Secret token must be at least 8 characters.';
            } elseif (strlen($token) > 128) {
                $errors['login_gate_token'] = 'Secret token must be 128 characters or fewer.';
            } elseif (!preg_match('/^[A-Za-z0-9_-]+$/', $token)) {
                $errors['login_gate_token'] = 'Use only letters, numbers, hyphens, and underscores.';
            }
        }

        if ($clearToken) {
            $existingHash = '';
        }

        $newHash = $existingHash;
        if ($token !== '' && empty($errors['login_gate_token'])) {
            $newHash = $this->hashToken($token);
            $plainToReveal = $token;
        }

        // Allow enable + new token in the same save — only block if enabling with no token at all.
        if ($enabled && $newHash === '') {
            $errors['login_gate_token'] = 'Enter a secret token (min 8 characters) to enable the login gate.';
        }

        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors, 'plain_token' => null];
        }

        $sm = $this->settings($db);
        $ok = $sm->set(self::SETTING_ENABLED, $enabled ? '1' : '0')
            && $sm->set(self::SETTING_TOKEN_HASH, $newHash)
            && $sm->set(self::SETTING_REDIRECT_URL, $redirectUrl)
            && $sm->set(self::SETTING_PARAM, $param);

        if (!$ok) {
            return [
                'success' => false,
                'errors' => ['general' => 'Failed to save login gate settings.'],
                'plain_token' => null,
            ];
        }

        // Rotating token, clearing token, disabling, or changing param invalidates pass cookies.
        if ($plainToReveal !== null || $clearToken || !$enabled || $param !== $previousParam) {
            $this->clearPassCookie();
        }

        return [
            'success' => true,
            'errors' => [],
            'plain_token' => $plainToReveal,
        ];
    }

    /**
     * Enforce gate on an auth page. Issues pass cookie when access is allowed.
     * Call only for unauthenticated visitors (after redirecting logged-in users).
     */
    public function protectAuthPage(mysqli $db): void
    {
        if (!$this->isEnabled($db)) {
            return;
        }

        if (!$this->validateAccess($db)) {
            $this->enforceOrRedirect($db);
        }

        $this->issuePassCookie();
    }

    private function settings(mysqli $db): SettingsManager
    {
        if ($this->settings === null) {
            $this->settings = new SettingsManager($db);
        }

        return $this->settings;
    }

    private function hasValidPassCookie(): bool
    {
        $raw = (string) ($_COOKIE[self::COOKIE_NAME] ?? '');
        if ($raw === '') {
            return false;
        }

        $parts = explode('.', $raw, 2);
        if (count($parts) !== 2) {
            return false;
        }

        [$expiresRaw, $signature] = $parts;
        if (!ctype_digit($expiresRaw)) {
            return false;
        }

        $expires = (int) $expiresRaw;
        if ($expires < time()) {
            return false;
        }

        $expected = hash_hmac('sha256', (string) $expires, $this->secret());
        return hash_equals($expected, $signature);
    }

    private function signCookiePayload(int $expires): string
    {
        $signature = hash_hmac('sha256', (string) $expires, $this->secret());
        return $expires . '.' . $signature;
    }

    private function secret(): string
    {
        if (defined('APP_KEY') && APP_KEY !== '') {
            return (string) APP_KEY;
        }

        return 'login-gate-dev-only';
    }

    private function setCookie(string $name, string $value, int $expires): void
    {
        if (headers_sent()) {
            return;
        }

        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

        setcookie($name, $value, [
            'expires' => $expires,
            'path' => '/',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
