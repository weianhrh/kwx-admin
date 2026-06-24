<?php
declare(strict_types=1);

const LEGACY_API_ROOT = __DIR__ . '/../lib';
const AUTH_COOKIE = 'session_token';
const AUTH_COOKIE_MAX_AGE = 2592000;

require_once LEGACY_API_ROOT . '/Database.php';

function auth_json_headers(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
}

function auth_handle_options(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function auth_out(int $code, string $msg, array $data = []): void
{
    echo json_encode([
        'code' => $code,
        'msg' => $msg,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function auth_request_data(): array
{
    $data = $_POST;
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $json = json_decode($raw ?: '', true);
        if (is_array($json)) {
            $data = array_merge($data, $json);
        }
    }
    return $data;
}

function auth_client_ip(): string
{
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
        if (empty($_SERVER[$key])) {
            continue;
        }
        $value = trim((string)$_SERVER[$key]);
        if ($key === 'HTTP_X_FORWARDED_FOR') {
            $value = trim(explode(',', $value)[0]);
        }
        if ($value !== '') {
            return $value;
        }
    }
    return 'unknown';
}

function auth_has_column(Database $db, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $rows = $db->query("SHOW COLUMNS FROM `$table` LIKE ?", [$column]);
    $cache[$key] = !empty($rows);
    return $cache[$key];
}

function auth_set_cookie(string $token): void
{
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    setcookie(AUTH_COOKIE, $token, [
        'expires' => time() + AUTH_COOKIE_MAX_AGE,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function auth_clear_cookie(): void
{
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    setcookie(AUTH_COOKIE, '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function auth_user_payload(array $user): array
{
    $roleId = (int)($user['role_id'] ?? 0);
    return [
        'id' => (int)($user['id'] ?? 0),
        'uid' => isset($user['uid']) ? (int)$user['uid'] : 0,
        'username' => (string)($user['username'] ?? ''),
        'role_id' => $roleId,
        'roles' => ['role_' . $roleId],
        'venue_id' => $user['venue_id'] ?? null,
        'venue_name' => $user['venue_name'] ?? null,
    ];
}

function auth_redis(): ?RedisHelper
{
    $file = LEGACY_API_ROOT . '/RedisHelper.php';
    if (!extension_loaded('redis') || !is_file($file)) {
        return null;
    }
    require_once $file;
    try {
        $redis = new RedisHelper();
        $redis->connect();
        $redis->selectDb(3);
        return $redis;
    } catch (Throwable $e) {
        error_log('[kwx-auth] redis unavailable: ' . $e->getMessage());
        return null;
    }
}
