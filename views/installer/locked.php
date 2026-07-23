<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Simple KUMA - Already Installed</title>
    <link rel="icon" type="image/x-icon" href="<?= htmlspecialchars($installerAssetsBase ?? '') ?>/assets/images/favicon.ico">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: linear-gradient(135deg, #2d5016 0%, #4a7c2c 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .box {
            background: #f5f1e8;
            border-radius: 8px;
            max-width: 560px;
            width: 100%;
            padding: 40px;
            text-align: center;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
        }
        h1 { color: #3d5a26; font-size: 24px; margin-bottom: 16px; }
        p { color: #666; line-height: 1.6; margin-bottom: 24px; }
        .btn {
            display: inline-block;
            padding: 12px 28px;
            background: #3d5a26;
            color: #f5f1e8;
            text-decoration: none;
            border-radius: 4px;
            font-size: 16px;
        }
        .btn:hover { background: #2d5016; }
        .note {
            margin-top: 24px;
            font-size: 13px;
            color: #999;
        }
    </style>
</head>
<body>
    <div class="box">
        <h1>Already Installed</h1>
        <p><?= htmlspecialchars($message ?? 'Simple KUMA is already installed and configured.') ?></p>
        <a href="<?= htmlspecialchars($loginUrl ?? 'login.php') ?>" class="btn">Go to Login</a>
        <p class="note">To reinstall, delete <code>config/config.php</code>, clear your database tables, then open <code>install.php</code> again.</p>
        <p class="note" style="margin-top:12px;">Stuck mid-install? Try <a href="install.php?step=migrations">install.php?step=migrations</a> or <a href="install.php?resume=migrations">install.php?resume=migrations</a>.</p>
    </div>
</body>
</html>
