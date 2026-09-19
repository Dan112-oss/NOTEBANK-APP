<?php
/**
 * POST /api/auth.php?action=login    { email, password }
 * POST /api/auth.php?action=register { full_name, email, password, password_confirm, university_id, ... }
 *
 * The student portal's own login/register pages (student/login.php,
 * student/register.php) post directly to themselves so the flow works
 * with JavaScript disabled; this JSON endpoint exists for the same
 * operations from a script or a future mobile client, sharing the
 * exact same auth/validation functions so behaviour never diverges.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/mail.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed.', 405);
}

api_csrf_require();
$input = json_input() ?: $_POST;
$action = $_GET['action'] ?? '';
$db = get_db();

if ($action === 'login') {
    $email = trim((string) ($input['email'] ?? ''));
    $password = (string) ($input['password'] ?? '');
    [$ok, $message] = student_attempt_login($email, $password);
    if (!$ok) {
        json_error($message, 401);
    }
    json_ok(['student' => current_student()]);
}

if ($action === 'register') {
    $v = new Validator($input);
    $v->required('full_name', 'Full name')->maxLength('full_name', 150, 'Full name')
      ->required('email', 'Email')->email('email')
      ->required('university_id', 'University')
      ->required('password', 'Password')
      ->matches('password_confirm', 'password', 'Password confirmation');

    if ($v->passes() && !is_strong_password($input['password'])) {
        json_error('Password must be at least 8 characters and include a letter and a number.', 422);
    }
    if ($v->fails()) {
        json_error($v->firstError(), 422);
    }

    $dupe = $db->prepare('SELECT id FROM students WHERE email = ?');
    $dupe->execute([trim($input['email'])]);
    if ($dupe->fetch()) {
        json_error('An account with this email already exists.', 409);
    }

    $stmt = $db->prepare(
        'INSERT INTO students (university_id, full_name, email, password_hash, student_number, department_id, level_id, email_verified, status)
         VALUES (:university_id, :full_name, :email, :password_hash, :student_number, :department_id, :level_id, 0, "active")'
    );
    $stmt->execute([
        'university_id' => (int) $input['university_id'],
        'full_name' => trim($input['full_name']),
        'email' => trim($input['email']),
        'password_hash' => password_hash($input['password'], PASSWORD_BCRYPT),
        'student_number' => trim($input['student_number'] ?? '') ?: null,
        'department_id' => !empty($input['department_id']) ? (int) $input['department_id'] : null,
        'level_id' => !empty($input['level_id']) ? (int) $input['level_id'] : null,
    ]);
    $studentId = (int) $db->lastInsertId();

    $rawToken = bin2hex(random_bytes(32));
    $db->prepare('INSERT INTO email_verifications (student_id, token_hash, expires_at) VALUES (:sid, :hash, DATE_ADD(NOW(), INTERVAL 48 HOUR))')
       ->execute(['sid' => $studentId, 'hash' => hash('sha256', $rawToken)]);
    $verifyUrl = base_url('student/verify-email.php?token=' . $rawToken . '&id=' . $studentId);
    send_mail(
        trim($input['email']),
        'Verify your Note Bank email',
        '<p>Welcome to Note Bank!</p><p><a href="' . $verifyUrl . '">Verify my email</a></p>',
        'verify_email',
        $studentId
    );

    student_attempt_login(trim($input['email']), $input['password']);
    json_ok(['student' => current_student()], 201);
}

json_error('Unknown action.', 400);
