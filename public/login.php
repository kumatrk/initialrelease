<?php

declare(strict_types=1);

/**
 * Simple KUMA - Login page.
 *
 * Split-screen sign-in. Colors and artwork follow the theme saved in the browser.
 */

if (!file_exists(__DIR__ . '/../config/config.php')) {
    header('Location: install.php');
    exit;
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/bootstrap_web_paths.php';

$skVersionData = is_file(__DIR__ . '/../version.php') ? include __DIR__ . '/../version.php' : [];
$skAppVersion = is_array($skVersionData) ? (string) ($skVersionData['version'] ?? '1.1.5.2') : '1.1.5.2';

use SimpleKuma\Auth\Auth;
use SimpleKuma\Auth\Csrf;
use SimpleKuma\Auth\LoginGate;
use SimpleKuma\Theme\ThemeRegistry;

$db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
$auth = new Auth($db);

if ($auth->isAuthenticated()) {
    header('Location: ' . APP_BASE_URL . '/index.php');
    exit;
}

$loginGate = new LoginGate();
$loginGate->protectAuthPage($db);

header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet', true);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate()) {
        $error = Csrf::invalidRequestMessage();
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $remember = isset($_POST['remember']);

        $result = $auth->login($username, $password, $remember);

        if ($result['success']) {
            header('Location: ' . APP_BASE_URL . '/index.php');
            exit;
        }

        $error = $result['message'];
    }
}

$db->close();
$themeClientConfig = ThemeRegistry::toClientConfig();
$loginArt = ASSETS_BASE_URL . '/assets/images/';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light" data-theme-base="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
    <meta name="googlebot" content="noindex, nofollow, noarchive, nosnippet">
    <script>
    (function () {
        try {
            var themes = <?= json_encode($themeClientConfig, JSON_THROW_ON_ERROR) ?>;
            var t = localStorage.getItem('kuma_theme');
            if (t && themes[t]) {
                document.documentElement.setAttribute('data-theme', t);
                document.documentElement.setAttribute('data-theme-base', themes[t].base === 'dark' ? 'dark' : 'light');
            }
        } catch (e) { /* ignore */ }
    })();
    </script>
    <title>Sign in</title>
    <link rel="icon" type="image/x-icon" href="<?= ASSETS_BASE_URL ?>/assets/images/favicon.ico">
    <style>
        *,
        *::before,
        *::after {
            box-sizing: border-box;
        }

        :root,
        [data-theme="light"] {
            color-scheme: light;
            --lp-page-bg: #ebe6dc;
            --lp-card-bg: #f7f4ee;
            --lp-panel-bg: #f6f3ec;
            --lp-border: #e0d9cc;
            --lp-input-bg: #ffffff;
            --lp-input-border: #d4cbbd;
            --lp-text: #2a261f;
            --lp-title: #241f18;
            --lp-text-muted: #6b6458;
            --lp-text-dim: #8a8376;
            --lp-rule-dim: #ddd4c6;
            --lp-accent: #3d5a26;
            --lp-accent-btn: #2d5016;
            --lp-accent-btn-hover: #243f12;
            --lp-on-accent: #ffffff;
            --lp-shadow: 0 18px 50px rgba(40, 55, 28, 0.18);
            --lp-art: url('<?= $loginArt ?>login-v2-light.jpg?v=2');
            --lp-art-mobile: url('<?= $loginArt ?>login-v2-light-mobile.jpg?v=2');
        }

        [data-theme="clean"] {
            color-scheme: light;
            --lp-page-bg: #d7e8f8;
            --lp-card-bg: #ffffff;
            --lp-panel-bg: #f8fbff;
            --lp-border: #d5e3f0;
            --lp-input-bg: #ffffff;
            --lp-input-border: #c5d7ea;
            --lp-text: #0f172a;
            --lp-title: #0f172a;
            --lp-text-muted: #64748b;
            --lp-text-dim: #94a3b8;
            --lp-rule-dim: #d5e3f0;
            --lp-accent: #2563eb;
            --lp-accent-btn: #2563eb;
            --lp-accent-btn-hover: #1d4ed8;
            --lp-on-accent: #ffffff;
            --lp-shadow: 0 18px 50px rgba(30, 64, 120, 0.16);
            --lp-art: url('<?= $loginArt ?>login-v2-clean.jpg?v=2');
            --lp-art-mobile: url('<?= $loginArt ?>login-v2-clean-mobile.jpg?v=2');
        }

        [data-theme="dark"] {
            color-scheme: dark;
            --lp-page-bg: #0b0d0f;
            --lp-card-bg: #16191d;
            --lp-panel-bg: #191c20;
            --lp-border: #262b31;
            --lp-input-bg: #101316;
            --lp-input-border: #2b3138;
            --lp-text: #e8edf3;
            --lp-title: #f2f5f8;
            --lp-text-muted: #9aa4af;
            --lp-text-dim: #6f7a85;
            --lp-rule-dim: #333a42;
            --lp-accent: #e23b3b;
            --lp-accent-btn: #c62828;
            --lp-accent-btn-hover: #e23b3b;
            --lp-on-accent: #ffffff;
            --lp-shadow: 0 24px 70px rgba(0, 0, 0, 0.6);
            --lp-art: url('<?= $loginArt ?>login-v2-dark.jpg');
            --lp-art-mobile: url('<?= $loginArt ?>login-v2-dark-mobile.jpg');
        }

        [data-theme="midnight"] {
            color-scheme: dark;
            --lp-page-bg: #111827;
            --lp-card-bg: #222e40;
            --lp-panel-bg: #192335;
            --lp-border: #38485d;
            --lp-input-bg: #192335;
            --lp-input-border: #38485d;
            --lp-text: #e2e8f0;
            --lp-title: #e2e8f0;
            --lp-text-muted: #aab8ca;
            --lp-text-dim: #94a3b8;
            --lp-rule-dim: #38485d;
            --lp-accent: #38bdf8;
            --lp-accent-btn: #38bdf8;
            --lp-accent-btn-hover: #7dd3fc;
            --lp-on-accent: #0b1725;
            --lp-shadow: 0 24px 70px rgba(0, 0, 0, 0.55);
            --lp-art: url('<?= $loginArt ?>login-v2-midnight.png');
            --lp-art-mobile: url('<?= $loginArt ?>login-v2-midnight-mobile.png');
        }

        [data-theme="onyx"] {
            color-scheme: dark;
            --lp-page-bg: #181a20;
            --lp-card-bg: #24262e;
            --lp-panel-bg: #202127;
            --lp-border: #45444a;
            --lp-input-bg: #1b1d23;
            --lp-input-border: #45444a;
            --lp-text: #f0e6d6;
            --lp-title: #f0e6d6;
            --lp-text-muted: #bdb4a8;
            --lp-text-dim: #b9ad9b;
            --lp-rule-dim: #45444a;
            --lp-accent: #d5a45d;
            --lp-accent-btn: #d5a45d;
            --lp-accent-btn-hover: #e6bc7b;
            --lp-on-accent: #201b14;
            --lp-shadow: 0 24px 70px rgba(0, 0, 0, 0.55);
            --lp-art: url('<?= $loginArt ?>login-v2-onyx.png');
            --lp-art-mobile: url('<?= $loginArt ?>login-v2-onyx-mobile.png');
        }

        [data-theme="timber"] {
            color-scheme: light;
            --lp-page-bg: #f5f0e8;
            --lp-card-bg: #ffffff;
            --lp-panel-bg: #f5f0e8;
            --lp-border: #ded7cb;
            --lp-input-bg: #ffffff;
            --lp-input-border: #ded7cb;
            --lp-text: #24352f;
            --lp-title: #24352f;
            --lp-text-muted: #65736b;
            --lp-text-dim: #65736b;
            --lp-rule-dim: #ded7cb;
            --lp-accent: #b66a4d;
            --lp-accent-btn: #b66a4d;
            --lp-accent-btn-hover: #985238;
            --lp-on-accent: #ffffff;
            --lp-shadow: 0 18px 50px rgba(39, 63, 58, 0.16);
            --lp-art: url('<?= $loginArt ?>login-v2-timber.png');
            --lp-art-mobile: url('<?= $loginArt ?>login-v2-timber-mobile.png');
        }

        [data-theme="executive"] {
            color-scheme: light;
            --lp-page-bg: #f4f6fa;
            --lp-card-bg: #ffffff;
            --lp-panel-bg: #f4f6fa;
            --lp-border: #dce3ef;
            --lp-input-bg: #ffffff;
            --lp-input-border: #dce3ef;
            --lp-text: #202c46;
            --lp-title: #202c46;
            --lp-text-muted: #63718a;
            --lp-text-dim: #63718a;
            --lp-rule-dim: #dce3ef;
            --lp-accent: #304f83;
            --lp-accent-btn: #304f83;
            --lp-accent-btn-hover: #223b66;
            --lp-on-accent: #ffffff;
            --lp-shadow: 0 18px 50px rgba(32, 44, 70, 0.14);
            --lp-art: url('<?= $loginArt ?>login-v2-executive.png');
            --lp-art-mobile: url('<?= $loginArt ?>login-v2-executive-mobile.png');
        }

        [data-theme="ember"] {
            color-scheme: light;
            --lp-page-bg: #fff9f2;
            --lp-card-bg: #ffffff;
            --lp-panel-bg: #fff9f2;
            --lp-border: #eaddd7;
            --lp-input-bg: #ffffff;
            --lp-input-border: #eaddd7;
            --lp-text: #412f36;
            --lp-title: #412f36;
            --lp-text-muted: #806b70;
            --lp-text-dim: #806b70;
            --lp-rule-dim: #eaddd7;
            --lp-accent: #c26b57;
            --lp-accent-btn: #c26b57;
            --lp-accent-btn-hover: #a9513f;
            --lp-on-accent: #ffffff;
            --lp-shadow: 0 18px 50px rgba(102, 59, 70, 0.14);
            --lp-art: url('<?= $loginArt ?>login-v2-ember.png');
            --lp-art-mobile: url('<?= $loginArt ?>login-v2-ember-mobile.png');
        }

        html,
        body {
            margin: 0;
            padding: 0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: var(--lp-page-bg);
            color: var(--lp-text);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 32px 20px;
            -webkit-font-smoothing: antialiased;
        }

        .lp-card {
            width: min(1024px, 100%);
            display: grid;
            grid-template-columns: 46% 54%;
            background: var(--lp-card-bg);
            border: 1px solid var(--lp-border);
            border-radius: 14px;
            overflow: hidden;
            box-shadow: var(--lp-shadow);
        }

        /* ── Left: branding artwork ── */
        .lp-visual {
            position: relative;
            background-image: var(--lp-art);
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            min-height: 500px;
        }

        /* ── Right: form panel with diagonal edge over the artwork ── */
        .lp-panel {
            position: relative;
            background: var(--lp-panel-bg);
            padding: 44px 56px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .lp-panel::before {
            content: '';
            position: absolute;
            top: 0;
            bottom: 0;
            left: -74px;
            width: 76px;
            background: var(--lp-panel-bg);
            clip-path: polygon(100% 0, 100% 100%, 0 100%);
        }

        .lp-panel > * {
            position: relative;
        }

        .lp-eyebrow {
            margin: 0;
            text-align: center;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 2.5px;
            text-transform: uppercase;
            color: var(--lp-accent);
        }

        .lp-title {
            margin: 10px 0 0;
            text-align: center;
            font-size: 23px;
            font-weight: 700;
            color: var(--lp-title);
        }

        .lp-title-rule {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            margin: 12px 0 24px;
        }

        .lp-title-rule span {
            display: block;
            height: 1px;
            width: 42px;
            background: var(--lp-rule-dim);
        }

        .lp-title-rule span:nth-child(2) {
            width: 22px;
            height: 2px;
            background: var(--lp-accent);
        }

        .lp-error {
            display: flex;
            gap: 8px;
            padding: 11px 14px;
            margin-bottom: 22px;
            font-size: 13px;
            line-height: 1.45;
            color: #f2b8b8;
            background: rgba(214, 59, 59, 0.12);
            border: 1px solid rgba(214, 59, 59, 0.4);
            border-left: 3px solid var(--lp-accent);
            border-radius: 6px;
        }

        .lp-field {
            margin-bottom: 15px;
        }

        .lp-label {
            display: block;
            margin-bottom: 7px;
            font-size: 13px;
            color: var(--lp-text-muted);
        }

        .lp-input-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }

        .lp-input-wrap .lp-icon {
            position: absolute;
            left: 13px;
            width: 16px;
            height: 16px;
            color: var(--lp-text-dim);
            pointer-events: none;
        }

        .lp-input {
            width: 100%;
            height: 44px;
            padding: 0 14px 0 38px;
            font-size: 14px;
            font-family: inherit;
            color: var(--lp-text);
            background: var(--lp-input-bg);
            border: 1px solid var(--lp-input-border);
            border-radius: 7px;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .lp-input::placeholder {
            color: var(--lp-text-dim);
        }

        .lp-input:focus {
            outline: none;
            border-color: var(--lp-accent);
            box-shadow: 0 0 0 3px color-mix(in srgb, var(--lp-accent) 22%, transparent);
        }

        .lp-input--password {
            padding-right: 44px;
        }

        .lp-reveal {
            position: absolute;
            right: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 34px;
            height: 34px;
            padding: 0;
            color: var(--lp-text-dim);
            background: transparent;
            border: 0;
            border-radius: 5px;
            cursor: pointer;
        }

        .lp-reveal:hover {
            color: var(--lp-text-muted);
        }

        .lp-reveal svg {
            width: 17px;
            height: 17px;
        }

        .lp-remember {
            display: flex;
            align-items: center;
            gap: 9px;
            margin: 16px 0 18px;
        }

        .lp-remember input {
            width: 16px;
            height: 16px;
            accent-color: var(--lp-accent-btn);
        }

        .lp-remember label {
            font-size: 13px;
            color: var(--lp-text-muted);
        }

        .lp-submit {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            width: 100%;
            height: 44px;
            font-family: inherit;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            color: var(--lp-on-accent);
            background: var(--lp-accent-btn);
            border: 0;
            border-radius: 7px;
            cursor: pointer;
            transition: background 0.2s;
        }

        .lp-submit:hover {
            background: var(--lp-accent-btn-hover);
        }

        .lp-submit img {
            width: 20px;
            height: 20px;
        }

        .lp-forgot {
            display: block;
            margin-top: 16px;
            text-align: center;
            font-size: 13px;
            color: var(--lp-accent);
            text-decoration: none;
        }

        .lp-forgot:hover {
            text-decoration: underline;
        }

        .lp-footer {
            margin-top: 28px;
            text-align: center;
            font-size: 12px;
            line-height: 1.6;
            color: var(--lp-text-dim);
        }

        .lp-footer span {
            font-size: 10px;
        }

        /* ── Narrow desktop / tablet landscape: keep the split, tighten padding ── */
        @media (max-width: 1000px) {
            .lp-panel {
                padding: 40px 34px;
            }
        }

        @media (max-height: 620px) and (min-width: 761px) {
            .lp-visual {
                min-height: 460px;
            }

            .lp-panel {
                padding: 34px 44px;
            }
        }

        /* ── Mobile: artwork becomes a banner above the form ── */
        @media (max-width: 760px) {
            body {
                align-items: flex-start;
                padding: 0;
            }

            .lp-card {
                grid-template-columns: 1fr;
                grid-template-rows: auto 1fr;
                width: 100%;
                min-height: 100vh;
                border: 0;
                border-radius: 0;
                box-shadow: none;
            }

            /*
             * Mobile uses a dedicated banner asset (logo lockup, no tagline).
             * Match its ~2.1:1 ratio so cover fills edge-to-edge without weird crop.
             */
            .lp-visual {
                align-self: start;
                width: 100%;
                height: auto;
                min-height: 0;
                aspect-ratio: 1024 / 488;
                max-height: 38vh;
                background-image: var(--lp-art-mobile);
                background-size: cover;
                background-position: center center;
                border-bottom: 0;
            }

            .lp-visual::after {
                content: '';
                position: absolute;
                left: 0;
                right: 0;
                bottom: 0;
                height: 40px;
                background: linear-gradient(to bottom, transparent, var(--lp-panel-bg));
                pointer-events: none;
            }

            .lp-panel {
                padding: 28px 24px 40px;
            }

            .lp-panel::before {
                display: none;
            }
        }

        @media (max-width: 760px) and (max-height: 720px) {
            .lp-visual {
                max-height: 30vh;
            }
        }

        @media (max-width: 420px) {
            .lp-panel {
                padding: 26px 18px 34px;
            }

            .lp-title {
                font-size: 20px;
            }
        }
    </style>
</head>
<body>
    <main class="lp-card">
        <div class="lp-visual" role="img" aria-label="Simple Kuma — Harder, Better, Faster, Stronger"></div>

        <div class="lp-panel">
            <p class="lp-eyebrow">Welcome Back</p>
            <h1 class="lp-title">Log in to your account</h1>
            <div class="lp-title-rule" aria-hidden="true">
                <span></span><span></span><span></span>
            </div>

            <?php if ($error): ?>
                <div class="lp-error" role="alert">
                    <span aria-hidden="true">⚠️</span>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <form method="post" action="">
                <?= Csrf::field() ?>

                <div class="lp-field">
                    <label class="lp-label" for="username">Username</label>
                    <div class="lp-input-wrap">
                        <svg class="lp-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                            <circle cx="12" cy="7" r="4"></circle>
                        </svg>
                        <input type="text"
                               id="username"
                               name="username"
                               class="lp-input"
                               placeholder="Enter your username"
                               required
                               autofocus
                               autocomplete="username">
                    </div>
                </div>

                <div class="lp-field">
                    <label class="lp-label" for="password">Password</label>
                    <div class="lp-input-wrap">
                        <svg class="lp-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <rect x="3" y="11" width="18" height="11" rx="2"></rect>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                        </svg>
                        <input type="password"
                               id="password"
                               name="password"
                               class="lp-input lp-input--password"
                               placeholder="Enter your password"
                               required
                               autocomplete="current-password">
                        <button type="button" class="lp-reveal" id="lp-reveal" aria-label="Show password" aria-pressed="false">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="lp-remember">
                    <input type="checkbox" id="remember" name="remember">
                    <label for="remember">Remember me for 30 days</label>
                </div>

                <button type="submit" class="lp-submit">
                    <img src="<?= ASSETS_BASE_URL ?>/assets/images/loginbear.png" alt="">
                    <span>Sign In</span>
                </button>
            </form>

            <a class="lp-forgot" href="<?= APP_BASE_URL ?>/forgot-password.php">Forgot your password?</a>

            <div class="lp-footer">
                V <?= htmlspecialchars($skAppVersion) ?><br>
                <span>Work is Never Over</span>
            </div>
        </div>
    </main>

    <script>
    (function () {
        var btn = document.getElementById('lp-reveal');
        var input = document.getElementById('password');
        if (!btn || !input) return;

        btn.addEventListener('click', function () {
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.setAttribute('aria-pressed', show ? 'true' : 'false');
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            input.focus();
        });
    })();
    </script>
</body>
</html>
