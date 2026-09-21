<?php
/**
 * Root index.php - HTTP Redirect to public/index.php
 * Uses HTTP redirect instead of PHP include to avoid security blocks
 */

// Preserve query string if present
$queryString = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';

// Redirect to public/index.php (works in subdirectory installs like /simplekuma/)
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
header('Location: ' . $base . '/public/index.php' . $queryString, true, 301);
exit;

