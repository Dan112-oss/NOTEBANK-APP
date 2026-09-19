<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

$db = get_db();
$studentId = (int) ($_GET['id'] ?? 0);
$token = (string) ($_GET['token'] ?? '');
$tokenHash = hash('sha256', $token);

$stmt = $db->prepare(
    'SELECT * FROM email_verifications WHERE student_id = :sid AND token_hash = :hash AND used_at IS NULL AND expires_at > NOW() LIMIT 1'
);
$stmt->execute(['sid' => $studentId, 'hash' => $tokenHash]);
$row = $stmt->fetch();

if ($row) {
    $db->prepare('UPDATE students SET email_verified = 1 WHERE id = :id')->execute(['id' => $studentId]);
    $db->prepare('UPDATE email_verifications SET used_at = NOW() WHERE id = :id')->execute(['id' => $row['id']]);
    if (current_student() && current_student()['id'] === $studentId) {
        refresh_student_session($studentId);
    }
    flash_set('success', 'Email verified — you can now check out.');
} else {
    flash_set('error', 'This verification link is invalid or has expired.');
}

redirect(current_student() ? 'student/dashboard.php' : 'student/login.php');
