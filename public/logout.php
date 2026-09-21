<?php

declare(strict_types=1);

/**
 * Simple KUMA - Logout
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/bootstrap_web_paths.php';

use SimpleKuma\Auth\Auth;
use SimpleKuma\Auth\LoginGate;

$db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
$auth = new Auth($db);
$auth->logout();

// Keep the login-gate pass cookie so the user can reach login.php again after logout.
// Clearing it would send them to the decoy URL when the gate is enabled.
$loginGate = new LoginGate();
$loginUrl = $loginGate->buildLoginUrl($db);
$db->close();

header('Location: ' . $loginUrl);
exit;

