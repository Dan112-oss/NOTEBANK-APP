<?php
/**
 * Shared bootstrap for every api/*.php endpoint: loads config/db/helpers
 * and provides a JSON-friendly auth guard (the page-level require_student()
 * in includes/auth.php redirects on failure, which is wrong for an API —
 * these return a 401 JSON envelope instead).
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validation.php';

function api_require_student(): array
{
    $student = current_student();
    if (!$student) {
        json_error('Please sign in to continue.', 401);
    }
    return $student;
}

/** CSRF check for JSON POST bodies — csrf_require() already reads the
 *  X-CSRF-Token header as a fallback, but responds with a plain-text
 *  419 page; API callers want a JSON envelope instead. */
function api_csrf_require(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? null);
    if (!csrf_verify($token)) {
        json_error('Your session expired. Please refresh the page and try again.', 419);
    }
}
