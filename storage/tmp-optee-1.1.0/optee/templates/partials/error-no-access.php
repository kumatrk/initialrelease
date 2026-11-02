<?php
/**
 * 403 error page for non-admin users.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Access Denied — Optee</title>
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f9fafb;
            color: #6b7280;
            font-family: Inter, system-ui, -apple-system, sans-serif;
        }
        .container { text-align: center; max-width: 400px; padding: 2rem; }
        h1 { color: #ef4444; font-size: 4rem; margin: 0; }
        h2 { color: #111827; font-size: 1.25rem; margin: 1rem 0 0.5rem; }
        p { line-height: 1.6; margin: 0.5rem 0; }
        a {
            color: #2aa5ff;
            text-decoration: none;
        }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="container">
        <h1>403</h1>
        <h2>Access Denied</h2>
        <p>You don't have permission to access this dashboard. Please contact an administrator.</p>
        <p><a href="<?php echo esc_url( wp_login_url() ); ?>">Log in with a different account</a></p>
    </div>
</body>
</html>
