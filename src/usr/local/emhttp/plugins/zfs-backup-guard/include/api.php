<?php
declare(strict_types=1);

/*
 * JSON-API der Weboberfläche. Nur POST, Unraid-csrf_token wird zusätzlich selbst geprüft.
 * Body: action=<name>&payload=<json>&csrf_token=<token>
 */

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function zbg_reply(int $code, array $body): never
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    zbg_reply(405, ['ok' => false, 'error' => 'Nur POST erlaubt']);
}

$var = @parse_ini_file('/var/local/emhttp/var.ini') ?: [];
if (empty($var['csrf_token']) || !hash_equals((string)$var['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
    zbg_reply(403, ['ok' => false, 'error' => 'Ungültiges CSRF-Token – Seite neu laden']);
}

$action = (string)($_POST['action'] ?? '');
$payload = json_decode((string)($_POST['payload'] ?? '{}'), true);

try {
    $data = ZBG\Api::dispatch($action, is_array($payload) ? $payload : []);
    zbg_reply(200, ['ok' => true, 'data' => $data]);
} catch (ZBG\UserError $e) {
    zbg_reply(400, ['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('zfs-backup-guard: ' . $e);
    zbg_reply(500, ['ok' => false, 'error' => $e->getMessage()]);
}
