<?php
/**
 * Kuma API — standalone page (keys + documentation).
 */
use SimpleKuma\Auth\Csrf;
use SimpleKuma\Auth\Permission;
use SimpleKuma\Auth\SingleAdminMode;
use SimpleKuma\Auth\AuditLogger;
use SimpleKuma\Entity\ApiKey;
use SimpleKuma\Api\OpenApiBuilder;

$apiKeyEntity = new ApiKey($db);
$auditLogger = new AuditLogger($db);
$errors = $errors ?? [];
$success = $success ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate()) {
        $errors['general'] = Csrf::invalidRequestMessage();
    } else {
        $action = $_POST['action'] ?? '';
        $canManageKeys = ($permission && $permission->hasPermission(Permission::PERM_SETTINGS_EDIT))
            || SingleAdminMode::isEnabled();

        if ($action === 'create_api_key') {
            if (!$canManageKeys) {
                $errors['general'] = 'Insufficient permissions';
            } else {
                $keyName = trim($_POST['api_key_name'] ?? '');
                $result = $apiKeyEntity->create((int)$_SESSION['user_id'], $keyName);
                if ($result['success']) {
                    $_SESSION['new_api_key_plain'] = $result['plain_key'];
                    $auditLogger->log('create', 'api_key', (int)$result['id'], 'API key created: ' . $keyName);
                    header('Location: ' . APP_BASE_URL . '/index.php?page=kuma-api&success=api_key_created');
                    exit;
                }
                $errors['api_key_name'] = $result['error'] ?? 'Failed to create API key';
            }
        } elseif ($action === 'revoke_api_key') {
            if (!$canManageKeys) {
                $errors['general'] = 'Insufficient permissions';
            } else {
                $keyId = (int)($_POST['api_key_id'] ?? 0);
                if ($apiKeyEntity->revoke($keyId, (int)$_SESSION['user_id'])) {
                    $auditLogger->log('delete', 'api_key', $keyId, 'API key revoked');
                    header('Location: ' . APP_BASE_URL . '/index.php?page=kuma-api&success=api_key_revoked');
                    exit;
                }
                $errors['general'] = 'Failed to revoke API key';
            }
        }
    }
}

$apiBaseUrl = OpenApiBuilder::apiBaseUrl();
$apiKeys = $currentUser ? $apiKeyEntity->getAllForUser((int)$currentUser['id']) : [];
$newApiKeyPlain = null;
if (isset($_SESSION['new_api_key_plain'])) {
    $newApiKeyPlain = $_SESSION['new_api_key_plain'];
    unset($_SESSION['new_api_key_plain']);
}
if (isset($_GET['success']) && $_GET['success'] === 'api_key_created' && !$newApiKeyPlain) {
    $success = $success ?: 'API key created successfully.';
}
if (isset($_GET['success']) && $_GET['success'] === 'api_key_revoked') {
    $success = $success ?: 'API key revoked.';
}

require __DIR__ . '/kuma-api-content.php';
