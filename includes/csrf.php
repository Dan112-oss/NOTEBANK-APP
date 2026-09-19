<?php
/**
 * CSRF protection for every state-changing form and API call.
 * One token per session; verified with a constant-time comparison.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

function csrf_token(): string
{
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}

function csrf_verify(?string $token): bool
{
    if (!$token || empty($_SESSION['_csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['_csrf_token'], $token);
}

/**
 * Call at the top of any POST handler. Halts with a 419-style error
 * page if the token is missing or wrong.
 */
function csrf_require(): void
{
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if (!csrf_verify($token)) {
        http_response_code(419);
        die('Your session expired or this form was submitted from an untrusted source. Please go back, refresh the page and try again.');
    }
}
