<?php
/**
 * Session-based authentication and authorization for both portals.
 * Admin and student sessions are kept in separate namespaces
 * ($_SESSION['admin'] / $_SESSION['student']) so one person's admin
 * login and student login (rare, but possible on the same browser)
 * never collide.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

// ---------------------------------------------------------------------
// Admin
// ---------------------------------------------------------------------

function admin_attempt_login(string $email, string $password): array
{
    if (rate_limit_hit('admin_login_' . client_ip(), 8, 300)) {
        return [false, 'Too many login attempts. Please wait a few minutes and try again.'];
    }

    $stmt = get_db()->prepare('SELECT * FROM admins WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => $email]);
    $admin = $stmt->fetch();

    if (!$admin || !password_verify($password, $admin['password_hash'])) {
        return [false, 'Incorrect email or password.'];
    }
    if ($admin['status'] !== 'active') {
        return [false, 'This admin account is suspended.'];
    }

    session_regenerate_id(true);
    $_SESSION['admin'] = [
        'id' => (int) $admin['id'],
        'full_name' => $admin['full_name'],
        'email' => $admin['email'],
        'role' => $admin['role'],
    ];

    $update = get_db()->prepare('UPDATE admins SET last_login_at = NOW() WHERE id = :id');
    $update->execute(['id' => $admin['id']]);

    log_audit((int) $admin['id'], 'login', 'admin', (int) $admin['id']);

    return [true, null];
}

function admin_logout(): void
{
    unset($_SESSION['admin']);
    session_regenerate_id(true);
}

function current_admin(): ?array
{
    return $_SESSION['admin'] ?? null;
}

/**
 * Guard for the top of every admin/*.php page. Never trust a hidden
 * form field or JS check for authorization — this runs server-side on
 * every request.
 */
function require_admin(array $roles = []): array
{
    $admin = current_admin();
    if (!$admin) {
        redirect('admin/login.php');
    }
    if ($roles !== [] && !in_array($admin['role'], $roles, true)) {
        http_response_code(403);
        die('You do not have permission to view this page.');
    }
    return $admin;
}

// ---------------------------------------------------------------------
// Student
// ---------------------------------------------------------------------

function student_attempt_login(string $email, string $password): array
{
    if (rate_limit_hit('student_login_' . client_ip(), 10, 300)) {
        return [false, 'Too many login attempts. Please wait a few minutes and try again.'];
    }

    $stmt = get_db()->prepare('SELECT * FROM students WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => $email]);
    $student = $stmt->fetch();

    if (!$student || !password_verify($password, $student['password_hash'])) {
        return [false, 'Incorrect email or password.'];
    }
    if ($student['status'] !== 'active') {
        return [false, 'This account has been suspended. Contact support for help.'];
    }

    session_regenerate_id(true);
    $_SESSION['student'] = [
        'id' => (int) $student['id'],
        'full_name' => $student['full_name'],
        'email' => $student['email'],
        'university_id' => (int) $student['university_id'],
        'department_id' => $student['department_id'] !== null ? (int) $student['department_id'] : null,
        'level_id' => $student['level_id'] !== null ? (int) $student['level_id'] : null,
        'email_verified' => (bool) $student['email_verified'],
    ];

    return [true, null];
}

function student_logout(): void
{
    unset($_SESSION['student']);
    session_regenerate_id(true);
}

function current_student(): ?array
{
    return $_SESSION['student'] ?? null;
}

function require_student(): array
{
    $student = current_student();
    if (!$student) {
        redirect('student/login.php');
    }
    return $student;
}

/** Refresh the cached session snapshot after a profile change. */
function refresh_student_session(int $studentId): void
{
    $stmt = get_db()->prepare('SELECT * FROM students WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $studentId]);
    $student = $stmt->fetch();
    if (!$student) {
        return;
    }
    $_SESSION['student'] = [
        'id' => (int) $student['id'],
        'full_name' => $student['full_name'],
        'email' => $student['email'],
        'university_id' => (int) $student['university_id'],
        'department_id' => $student['department_id'] !== null ? (int) $student['department_id'] : null,
        'level_id' => $student['level_id'] !== null ? (int) $student['level_id'] : null,
        'email_verified' => (bool) $student['email_verified'],
    ];
}

/**
 * True only when the currently authenticated student holds an
 * entitlement for $documentId — the ownership check required before
 * any preview or download is served.
 */
function student_owns_entitlement(int $studentId, int $documentId): ?array
{
    $stmt = get_db()->prepare(
        'SELECT * FROM entitlements WHERE student_id = :sid AND document_id = :did LIMIT 1'
    );
    $stmt->execute(['sid' => $studentId, 'did' => $documentId]);
    $row = $stmt->fetch();
    return $row ?: null;
}
