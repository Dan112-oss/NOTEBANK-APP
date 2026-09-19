<?php
/**
 * Shared helpers used across admin/, student/ and api/. Nothing here
 * touches session state (see auth.php) — this is formatting, small
 * DB-backed lookups, and generic utilities.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): never
{
    $url = str_starts_with($path, 'http') ? $path : rtrim(APP_URL, '/') . '/' . ltrim($path, '/');
    header('Location: ' . $url);
    exit;
}

function base_url(string $path = ''): string 
{
    // Render (and most PaaS platforms) terminate HTTPS at an edge proxy and
    // forward plain HTTP internally, signalling the original scheme via
    // X-Forwarded-Proto instead of $_SERVER['HTTPS']. Check both, or every
    // generated URL comes back http:// and gets blocked as mixed content
    // on a page actually served over https://.
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $scheme = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $baseUrl = $scheme . '://' . $host;
    return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
}


function asset_url(string $path): string
{
    return base_url('assets/' . ltrim($path, '/'));
}

/** One-time flash messages stored in the session. */
function flash_set(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

function flash_all(): array
{
    $messages = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $messages;
}

/**
 * Read a value from the settings table, cached for the request.
 */
function setting(string $key, ?string $default = null): ?string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $stmt = get_db()->query('SELECT setting_key, setting_value FROM settings');
        foreach ($stmt->fetchAll() as $row) {
            $cache[$row['setting_key']] = $row['setting_value'];
        }
    }
    return $cache[$key] ?? $default;
}

function set_setting(string $key, string $value): void
{
    $stmt = get_db()->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $stmt->execute(['k' => $key, 'v' => $value]);
}

function format_money(float|string $amount, ?string $currency = null): string
{
    $currency = $currency ?? setting('default_currency', 'XAF');
    $symbol = setting('currency_symbol', 'FCFA');
    // CFA francs have no minor/decimal subunit in everyday use.
    return number_format((float) $amount, 0) . ' ' . $symbol;
}

/**
 * Money formatting for generated PDFs. Kept as a separate function
 * from format_money() (rather than reusing it) so a future currency
 * with a non-WinAnsi symbol can swap in an ASCII-safe fallback here
 * without touching every web page's formatting.
 */
function format_money_pdf(float|string $amount, ?string $currency = null): string
{
    $currency = $currency ?? setting('default_currency', 'XAF');
    return number_format((float) $amount, 0) . ' ' . $currency;
}

function random_filename(string $extension): string
{
    return bin2hex(random_bytes(16)) . '.' . ltrim($extension, '.');
}

function generate_order_number(): string
{
    return 'NB-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(4)));
}

function generate_invoice_number(): string
{
    return 'INV-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(4)));
}

/**
 * Sign a short-lived token for preview/download URLs so a document
 * cannot be fetched from a predictable, permanent link. Format:
 * base64url(payload).base64url(hmac)
 */
function sign_token(array $payload, int $ttlSeconds = 900): string
{
    $payload['exp'] = time() + $ttlSeconds;
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $encoded = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    $hmac = hash_hmac('sha256', $encoded, APP_KEY ?: 'insecure-fallback-key');
    $encodedHmac = rtrim(strtr(base64_encode($hmac), '+/', '-_'), '=');
    return $encoded . '.' . $encodedHmac;
}

/** Returns the decoded payload array, or null if invalid/expired. */
function verify_token(string $token): ?array
{
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) {
        return null;
    }
    [$encoded, $encodedHmac] = $parts;
    $hmac = hash_hmac('sha256', $encoded, APP_KEY ?: 'insecure-fallback-key');
    $expectedEncodedHmac = rtrim(strtr(base64_encode($hmac), '+/', '-_'), '=');
    if (!hash_equals($expectedEncodedHmac, $encodedHmac)) {
        return null;
    }
    $json = base64_decode(strtr($encoded, '-_', '+/'));
    $payload = json_decode((string) $json, true);
    if (!is_array($payload) || !isset($payload['exp']) || $payload['exp'] < time()) {
        return null;
    }
    return $payload;
}

function log_audit(?int $adminId, string $action, string $entityType, ?int $entityId = null, ?string $details = null): void
{
    $stmt = get_db()->prepare(
        'INSERT INTO audit_logs (admin_id, action, entity_type, entity_id, details, ip_address, created_at)
         VALUES (:admin_id, :action, :entity_type, :entity_id, :details, :ip, NOW())'
    );
    $stmt->execute([
        'admin_id' => $adminId,
        'action' => $action,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'details' => $details,
        'ip' => client_ip(),
    ]);
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/** JSON envelope for api/ endpoints: { success, data } or { success, error }. */
function json_ok(array $data = [], int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'data' => $data]);
    exit;
}

function json_error(string $message, int $status = 400): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

/** Reads and decodes a JSON request body into an associative array. */
function json_input(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode((string) $raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Very small rate limiter backed by the session, e.g. for login
 * attempts. Not a substitute for a proper store under real load, but
 * enough to slow down brute-force attempts on a single deployment.
 */
function rate_limit_hit(string $key, int $maxAttempts, int $windowSeconds): bool
{
    $now = time();
    $bucket = $_SESSION['_rate'][$key] ?? ['count' => 0, 'reset' => $now + $windowSeconds];
    if ($now > $bucket['reset']) {
        $bucket = ['count' => 0, 'reset' => $now + $windowSeconds];
    }
    $bucket['count']++;
    $_SESSION['_rate'][$key] = $bucket;
    return $bucket['count'] > $maxAttempts;
}

function cart_item_count(int $studentId): int
{
    $stmt = get_db()->prepare('SELECT COALESCE(SUM(quantity),0) FROM cart_items WHERE student_id = :id');
    $stmt->execute(['id' => $studentId]);
    return (int) $stmt->fetchColumn();
}

function human_date(?string $datetime): string
{
    if (!$datetime) {
        return '—';
    }
    return date('d M Y, g:ia', strtotime($datetime));
}
