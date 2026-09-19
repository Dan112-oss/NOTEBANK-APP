<?php
/**
 * Core application configuration.
 *
 * Loads environment variables from .env, defines path/URL constants,
 * configures session security and PHP error handling. Every entry
 * script in admin/, student/ and api/ must require this file first
 * (directly or via includes/auth.php, which requires it).
 */

declare(strict_types=1);

if (defined('NOTEBANK_CONFIG_LOADED')) {
    return;
}
define('NOTEBANK_CONFIG_LOADED', true);

define('BASE_PATH', dirname(__DIR__));

/**
 * Minimal .env loader. No external dependency required: this keeps the
 * app runnable immediately after `git clone` + `composer install`.
 */
function notebank_load_env(string $path): void
{
    if (!is_readable($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        $value = trim($value, "\"'");
        if ($name !== '' && getenv($name) === false) {
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
        }
    }
}

notebank_load_env(BASE_PATH . '/.env');

function env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }
    return $value;
}

define('APP_NAME', env('APP_NAME', 'Note Bank'));
define('APP_ENV', env('APP_ENV', 'production'));
define('APP_URL', env('APP_URL', 'http://localhost/'));
define('APP_KEY', env('APP_KEY', ''));
define('IS_PRODUCTION', APP_ENV === 'production');

define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_PORT', env('DB_PORT', '3306'));
define('DB_NAME', env('DB_NAME', 'notebank'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', env('DB_PASS', ''));


define('SMTP_HOST', env('SMTP_HOST', ''));
define('SMTP_PORT', (int) env('SMTP_PORT', '587'));
define('SMTP_ENCRYPTION', env('SMTP_ENCRYPTION', 'tls'));
define('SMTP_USER', env('SMTP_USER', ''));
define('SMTP_PASS', env('SMTP_PASS', ''));
define('SMTP_FROM_EMAIL', env('SMTP_FROM_EMAIL', 'no-reply@notebank.test'));
define('SMTP_FROM_NAME', env('SMTP_FROM_NAME', 'Note Bank'));

define('PDFTOPPM_BIN', env('PDFTOPPM_BIN', 'pdftoppm'));
define('PDFINFO_BIN', env('PDFINFO_BIN', 'pdfinfo'));

// Protected storage — never inside a publicly served directory.
define('STORAGE_PATH', BASE_PATH . '/storage');
define('STORAGE_ORIGINAL', STORAGE_PATH . '/original');
define('STORAGE_PREVIEW', STORAGE_PATH . '/preview');
define('STORAGE_PROCESSED', STORAGE_PATH . '/processed');
define('STORAGE_RECEIPTS', STORAGE_PATH . '/receipts');

foreach ([STORAGE_ORIGINAL, STORAGE_PREVIEW, STORAGE_PROCESSED, STORAGE_RECEIPTS] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// Upload constraints.
define('MAX_UPLOAD_BYTES', 25 * 1024 * 1024); // 25 MB per document
define('MAX_PROOF_UPLOAD_BYTES', 8 * 1024 * 1024); // 8 MB per payment proof
define('ALLOWED_DOCUMENT_MIME', ['application/pdf']);
define('ALLOWED_PROOF_MIME', ['application/pdf', 'image/jpeg', 'image/png']);

if (IS_PRODUCTION) {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}
ini_set('log_errors', '1');
ini_set('error_log', BASE_PATH . '/storage/php-error.log');

date_default_timezone_set('Africa/Lagos');

// Session hardening. Must run before any output/session_start() call.
if (session_status() === PHP_SESSION_NONE) {
    // Only mark the cookie "secure" if this request actually arrived over
    // HTTPS. Forcing it to true in production would silently break every
    // session (and therefore CSRF) on hosts that don't have SSL active yet.
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');

    $cookieParams = [
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    session_set_cookie_params($cookieParams);
    session_name('notebank_session');
    session_start();
}

if (empty(APP_KEY)) {
    // A missing signing key is a deployment error, not something to
    // silently work around — token signing (preview/download links)
    // depends on it.
    error_log('NOTE BANK: APP_KEY is not set in .env — signed URLs will be insecure.');
}
