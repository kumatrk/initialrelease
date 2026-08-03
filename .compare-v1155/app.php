<?php
/**
 * Alternative Entry Point - app.php
 * Uses HTTP redirect instead of PHP include to avoid security blocks
 */

// Preserve query string if present
$queryString = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';

$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
header('Location: ' . $base . '/public/index.php' . $queryString, true, 301);
exit;

