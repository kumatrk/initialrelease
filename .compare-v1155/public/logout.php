<?php

declare(strict_types=1);

/**
 * Simple KUMA - Logout
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/bootstrap_web_paths.php';

use SimpleKuma\Auth\Auth;

$db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
$auth = new Auth($db);
$auth->logout();
$db->close();

// Use BASE_URL for proper redirect in any directory
header('Location: ' . APP_BASE_URL . '/login.php');
exit;

